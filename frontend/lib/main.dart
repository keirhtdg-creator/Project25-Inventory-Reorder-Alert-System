import 'package:flutter/material.dart';

import 'api_client.dart';

void main() {
  runApp(const InventoryApp());
}

class InventoryApp extends StatelessWidget {
  const InventoryApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Inventory Reorder Alerts',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFF176B62),
          surface: const Color(0xFFF7F8F6),
        ),
        useMaterial3: true,
        scaffoldBackgroundColor: const Color(0xFFF7F8F6),
      ),
      home: const InventoryHome(),
    );
  }
}

class InventoryHome extends StatefulWidget {
  const InventoryHome({super.key, this.api});

  final ApiClient? api;

  @override
  State<InventoryHome> createState() => _InventoryHomeState();
}

class _InventoryHomeState extends State<InventoryHome> {
  late final ApiClient _api;
  int _selectedPage = 0;
  bool _loading = true;
  String? _loadError;
  List<Map<String, dynamic>> _items = [];
  List<Map<String, dynamic>> _alerts = [];
  List<Map<String, dynamic>> _reorders = [];
  List<Map<String, dynamic>> _summaries = [];
  List<Map<String, dynamic>> _manualActions = [];

  static const _pages = [
    'Inventory',
    'Alerts',
    'Reorder queue',
    'Weekly risk',
    'Manual actions',
  ];

  @override
  void initState() {
    super.initState();
    _api = widget.api ?? ApiClient();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _loadError = null;
    });
    try {
      final results = await Future.wait([
        _api.getItems(),
        _api.getAlerts(),
        _api.getReorders(),
        _api.getWeeklySummaries(),
        _api.getManualActions(),
      ]);
      if (!mounted) return;
      setState(() {
        _items = results[0];
        _alerts = results[1];
        _reorders = results[2];
        _summaries = results[3];
        _manualActions = results[4];
        _loading = false;
      });
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _loadError = _friendlyError(error);
        _loading = false;
      });
    }
  }

  Future<void> _perform(
    Future<void> Function() action,
    String successMessage,
  ) async {
    try {
      await action();
      await _load();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            _loadError == null
                ? successMessage
                : 'Saved, but the latest data could not be refreshed: $_loadError',
          ),
        ),
      );
    } on Object catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(_friendlyError(error)),
          backgroundColor: Theme.of(context).colorScheme.error,
        ),
      );
    }
  }

  String _friendlyError(Object error) {
    if (error is ApiException && error.isAuthorizationError) {
      return '${error.statusCode}: ${error.message} Authentication and role enforcement are scheduled for Week 14.';
    }
    if (error is ApiException) {
      return error.message;
    }

    return 'Unexpected error while handling the API response. Try again, and report it if it persists.';
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= 820;
        final body = _buildPage();
        return Scaffold(
          appBar: AppBar(
            title: const Text(
              'Inventory Reorder Alerts',
              style: TextStyle(fontWeight: FontWeight.w700),
            ),
            actions: [
              IconButton(
                tooltip: 'Refresh',
                onPressed: _loading ? null : _load,
                icon: const Icon(Icons.refresh),
              ),
              const SizedBox(width: 12),
            ],
          ),
          body: wide
              ? Row(
                  children: [
                    NavigationRail(
                      selectedIndex: _selectedPage,
                      onDestinationSelected: (index) {
                        setState(() => _selectedPage = index);
                      },
                      labelType: NavigationRailLabelType.all,
                      destinations: _railDestinations,
                    ),
                    const VerticalDivider(width: 1),
                    Expanded(child: body),
                  ],
                )
              : body,
          bottomNavigationBar: wide
              ? null
              : NavigationBar(
                  selectedIndex: _selectedPage,
                  onDestinationSelected: (index) {
                    setState(() => _selectedPage = index);
                  },
                  destinations: _barDestinations,
                ),
        );
      },
    );
  }

  List<NavigationRailDestination> get _railDestinations => const [
    NavigationRailDestination(
      icon: Icon(Icons.inventory_2_outlined),
      selectedIcon: Icon(Icons.inventory_2),
      label: Text('Inventory'),
    ),
    NavigationRailDestination(
      icon: Icon(Icons.notifications_none),
      selectedIcon: Icon(Icons.notifications),
      label: Text('Alerts'),
    ),
    NavigationRailDestination(
      icon: Icon(Icons.shopping_cart_outlined),
      selectedIcon: Icon(Icons.shopping_cart),
      label: Text('Reorders'),
    ),
    NavigationRailDestination(
      icon: Icon(Icons.insights_outlined),
      selectedIcon: Icon(Icons.insights),
      label: Text('Weekly risk'),
    ),
    NavigationRailDestination(
      icon: Icon(Icons.support_agent_outlined),
      selectedIcon: Icon(Icons.support_agent),
      label: Text('Manual actions'),
    ),
  ];

  List<NavigationDestination> get _barDestinations => const [
    NavigationDestination(
      icon: Icon(Icons.inventory_2_outlined),
      selectedIcon: Icon(Icons.inventory_2),
      label: 'Inventory',
    ),
    NavigationDestination(
      icon: Icon(Icons.notifications_none),
      selectedIcon: Icon(Icons.notifications),
      label: 'Alerts',
    ),
    NavigationDestination(
      icon: Icon(Icons.shopping_cart_outlined),
      selectedIcon: Icon(Icons.shopping_cart),
      label: 'Reorders',
    ),
    NavigationDestination(
      icon: Icon(Icons.insights_outlined),
      selectedIcon: Icon(Icons.insights),
      label: 'Weekly risk',
    ),
    NavigationDestination(
      icon: Icon(Icons.support_agent_outlined),
      selectedIcon: Icon(Icons.support_agent),
      label: 'Manual actions',
    ),
  ];

  Widget _buildPage() {
    if (_loading && _items.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_loadError != null && _items.isEmpty) {
      return _ErrorPanel(message: _loadError!, onRetry: _load);
    }

    final page = switch (_selectedPage) {
      0 => _inventoryPage(),
      1 => _alertsPage(),
      2 => _reordersPage(),
      3 => _weeklyRiskPage(),
      _ => _manualActionsPage(),
    };
    if (_loadError == null) return page;

    return Column(
      children: [
        MaterialBanner(
          content: Text('Showing saved data; refresh failed: $_loadError'),
          leading: const Icon(Icons.cloud_off_outlined),
          actions: [TextButton(onPressed: _load, child: const Text('Retry'))],
        ),
        Expanded(child: page),
      ],
    );
  }

  Widget _inventoryPage() {
    final atRisk = _items
        .where((item) => item['below_threshold'] == true)
        .length;
    return _PageFrame(
      title: _pages[_selectedPage],
      subtitle: 'Record stock movements and keep supply levels visible.',
      action: FilledButton.icon(
        onPressed: _showCreateItemDialog,
        icon: const Icon(Icons.add),
        label: const Text('Add item'),
      ),
      child: ListView(
        children: [
          _SummaryRow(
            children: [
              _SummaryCard(
                label: 'Items',
                value: '${_items.length}',
                icon: Icons.inventory_2_outlined,
              ),
              _SummaryCard(
                label: 'At or below threshold',
                value: '$atRisk',
                icon: Icons.warning_amber_outlined,
              ),
              _SummaryCard(
                label: 'Open reorders',
                value:
                    '${_reorders.where((item) => _isActive(item['status'])).length}',
                icon: Icons.shopping_cart_outlined,
              ),
            ],
          ),
          const SizedBox(height: 18),
          if (_items.isEmpty)
            const SizedBox(
              height: 220,
              child: _EmptyPanel(
                message: 'No inventory items yet. Add an item to begin.',
              ),
            )
          else
            ..._items.expand(
              (item) => [_inventoryCard(item), const SizedBox(height: 10)],
            ),
        ],
      ),
    );
  }

  Widget _inventoryCard(Map<String, dynamic> item) {
    final below = item['below_threshold'] == true;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Wrap(
          alignment: WrapAlignment.spaceBetween,
          crossAxisAlignment: WrapCrossAlignment.center,
          runSpacing: 12,
          spacing: 16,
          children: [
            SizedBox(
              width: 260,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    item['name']?.toString() ?? 'Unnamed item',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 4),
                  Text('${item['sku']} · ${item['unit']}'),
                ],
              ),
            ),
            Text('On hand  ${_qty(item['on_hand_quantity'])}'),
            Text('Threshold  ${_qty(item['reorder_threshold'])}'),
            Chip(
              avatar: Icon(
                below ? Icons.warning_amber : Icons.check_circle_outline,
                size: 18,
              ),
              label: Text(below ? 'Needs attention' : 'In range'),
              backgroundColor: below
                  ? Theme.of(context).colorScheme.errorContainer
                  : Theme.of(context).colorScheme.secondaryContainer,
            ),
            Wrap(
              spacing: 8,
              children: [
                OutlinedButton(
                  onPressed: () => _showTransactionDialog(item, 'issue'),
                  child: const Text('Issue'),
                ),
                OutlinedButton(
                  onPressed: () => _showTransactionDialog(item, 'receipt'),
                  child: const Text('Receive'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _alertsPage() => _PageFrame(
    title: _pages[_selectedPage],
    subtitle: 'Threshold alerts are created when on-hand stock is at or below its limit.',
    child: _alerts.isEmpty
        ? const _EmptyPanel(message: 'No alerts have been generated.')
        : ListView.separated(
            itemCount: _alerts.length,
            separatorBuilder: (_, _) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              final alert = _alerts[index];
              return Card(
                child: ListTile(
                  leading: const Icon(Icons.notifications_active_outlined),
                  title: Text('${alert['item_name']} · ${alert['sku']}'),
                  subtitle: Text(
                    'Episode ${alert['episode_number']} · ${alert['status']} · ${_date(alert['triggered_at'])}',
                  ),
                  trailing: alert['status'] == 'open'
                      ? OutlinedButton(
                          onPressed: () => _perform(
                            () => _api.acknowledgeAlert(alert['id'] as int),
                            'Alert acknowledged.',
                          ),
                          child: const Text('Acknowledge'),
                        )
                      : Chip(label: Text(alert['status'].toString())),
                ),
              );
            },
          ),
  );

  Widget _reordersPage() => _PageFrame(
    title: _pages[_selectedPage],
    subtitle: 'Review recommendations, update procurement, record receipt, then close.',
    child: _reorders.isEmpty
        ? const _EmptyPanel(message: 'No reorder recommendations yet.')
        : ListView.separated(
            itemCount: _reorders.length,
            separatorBuilder: (_, _) => const SizedBox(height: 10),
            itemBuilder: (context, index) => _reorderCard(_reorders[index]),
          ),
  );

  Widget _reorderCard(Map<String, dynamic> reorder) {
    final status = reorder['status']?.toString() ?? 'unknown';
    final id = reorder['id'] as int;
    final actions = <Widget>[];
    if (status == 'pending_review') {
      actions.addAll([
        FilledButton(
          onPressed: () => _perform(
            () => _api.reviewReorder(id, 'approve'),
            'Recommendation approved.',
          ),
          child: const Text('Approve'),
        ),
        OutlinedButton(
          onPressed: () => _perform(
            () => _api.reviewReorder(id, 'reject'),
            'Recommendation rejected.',
          ),
          child: const Text('Reject'),
        ),
      ]);
    } else if (status == 'approved') {
      actions.add(
        FilledButton(
          onPressed: () => _perform(
            () => _api.markOrdered(id),
            'Procurement status updated.',
          ),
          child: const Text('Mark ordered'),
        ),
      );
    } else if (status == 'ordered' || status == 'partially_received') {
      actions.add(
        OutlinedButton(
          onPressed: () => _showReceiptDialog(reorder),
          child: const Text('Record receipt'),
        ),
      );
    } else if (status == 'received') {
      actions.add(
        FilledButton(
          onPressed: () => _perform(
            () =>
                _api.closeReorder(id, 'Receipt confirmed and workflow closed.'),
            'Reorder closed.',
          ),
          child: const Text('Close'),
        ),
      );
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${reorder['item_name']} · ${reorder['sku']}',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                Chip(label: Text(status.replaceAll('_', ' '))),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              'Suggested ${_qty(reorder['suggested_quantity'])} ${reorder['unit']} · received ${_qty(reorder['received_quantity'])}',
            ),
            const SizedBox(height: 12),
            Wrap(spacing: 8, runSpacing: 8, children: actions),
          ],
        ),
      ),
    );
  }

  Widget _weeklyRiskPage() => _PageFrame(
    title: _pages[_selectedPage],
    subtitle: 'Weekly snapshots cover Monday–Sunday and run Mondays at 08:00 Asia/Manila.',
    child: _summaries.isEmpty
        ? const _EmptyPanel(
            message: 'No weekly summaries yet. The first summary is created by the scheduled job.',
          )
        : ListView.separated(
            itemCount: _summaries.length,
            separatorBuilder: (_, _) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              final summary = _summaries[index];
              final items = List<Map<String, dynamic>>.from(
                summary['items'] as List,
              );
              return Card(
                child: ExpansionTile(
                  title: Text(
                    '${summary['week_start']} to ${summary['week_end']}',
                  ),
                  subtitle: Text(
                    '${summary['risk_item_count']} item(s) at risk · generated ${_date(summary['generated_at'])}',
                  ),
                  children: items.isEmpty
                      ? [
                          const ListTile(
                            title: Text(
                              'No approved inventory was at risk for this period.',
                            ),
                          ),
                        ]
                      : items.map((item) {
                          final atRisk =
                              (double.tryParse(
                                    item['on_hand_quantity'].toString(),
                                  ) ??
                                  0) <=
                              (double.tryParse(
                                    item['reorder_threshold'].toString(),
                                  ) ??
                                  0);
                          return ListTile(
                            title: Text(
                              '${item['item_name']} · ${item['sku']}',
                            ),
                            subtitle: Text(
                              'Stock ${_qty(item['on_hand_quantity'])} / threshold ${_qty(item['reorder_threshold'])} · reorder ${item['reorder_status'] ?? 'none'}',
                            ),
                            trailing: atRisk
                                ? const Icon(
                                    Icons.warning_amber,
                                    color: Colors.deepOrange,
                                  )
                                : null,
                          );
                        }).toList(),
                ),
              );
            },
          ),
  );

  Widget _manualActionsPage() => _PageFrame(
    title: _pages[_selectedPage],
    subtitle: 'Permanent automation failures remain here until a human records a resolution.',
    child: _manualActions.isEmpty
        ? const _EmptyPanel(
            message: 'There are no manual actions requiring attention.',
          )
        : ListView.separated(
            itemCount: _manualActions.length,
            separatorBuilder: (_, _) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              final action = _manualActions[index];
              final isOpen = action['status'] == 'open';
              return Card(
                child: ListTile(
                  leading: Icon(
                    isOpen ? Icons.support_agent : Icons.check_circle_outline,
                  ),
                  title: Text(
                    action['reason']?.toString() ?? 'Human action required',
                  ),
                  subtitle: Text(
                    '${action['item_name'] ?? 'Automation'} · ${action['status']} · ${_date(action['created_at'])}',
                  ),
                  isThreeLine: true,
                  trailing: isOpen
                      ? OutlinedButton(
                          onPressed: () => _perform(
                            () => _api.resolveManualAction(
                              action['id'] as int,
                              'Supervisor reviewed and recorded the corrective action.',
                            ),
                            'Manual action resolved.',
                          ),
                          child: const Text('Resolve'),
                        )
                      : null,
                ),
              );
            },
          ),
  );

  Future<void> _showTransactionDialog(
    Map<String, dynamic> item,
    String type,
  ) async {
    final quantity = await showDialog<double>(
      context: context,
      builder: (context) => _QuantityDialog(
        title: type == 'issue' ? 'Issue stock' : 'Receive stock',
        itemName: item['name'].toString(),
      ),
    );
    if (quantity == null) return;
    await _perform(
      () => _api.recordTransaction(item['id'] as int, type, quantity),
      'Stock transaction recorded.',
    );
  }

  Future<void> _showReceiptDialog(Map<String, dynamic> reorder) async {
    final quantity = await showDialog<double>(
      context: context,
      builder: (context) => _QuantityDialog(
        title: 'Record reorder receipt',
        itemName: reorder['item_name'].toString(),
      ),
    );
    if (quantity == null) return;
    await _perform(
      () => _api.receiveReorder(reorder['id'] as int, quantity),
      'Receipt recorded.',
    );
  }

  Future<void> _showCreateItemDialog() async {
    final values = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (context) => const _CreateItemDialog(),
    );
    if (values == null) return;
    await _perform(() => _api.createItem(values), 'Inventory item created.');
  }

  bool _isActive(dynamic status) => const {
    'pending_review',
    'approved',
    'ordered',
    'partially_received',
    'received',
  }.contains(status);

  String _qty(dynamic value) {
    final parsed = double.tryParse(value.toString());
    if (parsed == null) return value.toString();
    return parsed.toStringAsFixed(3).replaceFirst(RegExp(r'\.?0+$'), '');
  }

  String _date(dynamic value) {
    if (value == null) return '—';
    final parsed = DateTime.tryParse(value.toString());
    if (parsed == null) return value.toString();
    return '${parsed.year}-${parsed.month.toString().padLeft(2, '0')}-${parsed.day.toString().padLeft(2, '0')}';
  }
}

class _PageFrame extends StatelessWidget {
  const _PageFrame({
    required this.title,
    required this.subtitle,
    required this.child,
    this.action,
  });

  final String title;
  final String subtitle;
  final Widget child;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.center,
            runSpacing: 12,
            spacing: 16,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: Theme.of(context).textTheme.headlineSmall
                        ?.copyWith(fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 4),
                  Text(subtitle),
                ],
              ),
              ?action,
            ],
          ),
          const SizedBox(height: 24),
          Expanded(child: child),
        ],
      ),
    );
  }
}

class _SummaryRow extends StatelessWidget {
  const _SummaryRow({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) =>
      Wrap(spacing: 12, runSpacing: 12, children: children);
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({
    required this.label,
    required this.value,
    required this.icon,
  });

  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 220,
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              Icon(icon, color: Theme.of(context).colorScheme.primary),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      value,
                      style: Theme.of(context).textTheme.titleLarge
                          ?.copyWith(fontWeight: FontWeight.bold),
                    ),
                    Text(label, style: Theme.of(context).textTheme.bodySmall),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _EmptyPanel extends StatelessWidget {
  const _EmptyPanel({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.inbox_outlined, size: 42),
          const SizedBox(height: 12),
          Text(message, textAlign: TextAlign.center),
        ],
      ),
    ),
  );
}

class _ErrorPanel extends StatelessWidget {
  const _ErrorPanel({required this.message, required this.onRetry});

  final String message;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) => Center(
    child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 520),
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.cloud_off_outlined, size: 42),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh),
              label: const Text('Retry'),
            ),
          ],
        ),
      ),
    ),
  );
}

class _QuantityDialog extends StatefulWidget {
  const _QuantityDialog({required this.title, required this.itemName});

  final String title;
  final String itemName;

  @override
  State<_QuantityDialog> createState() => _QuantityDialogState();
}

class _QuantityDialogState extends State<_QuantityDialog> {
  final _formKey = GlobalKey<FormState>();
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(widget.title),
    content: Form(
      key: _formKey,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(widget.itemName),
          const SizedBox(height: 12),
          TextFormField(
            controller: _controller,
            autofocus: true,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(
              labelText: 'Quantity',
              border: OutlineInputBorder(),
            ),
            validator: (value) {
              final parsed = double.tryParse(value?.trim() ?? '');
              if (parsed == null || parsed <= 0) {
                return 'Enter a quantity greater than zero.';
              }
              return null;
            },
          ),
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      FilledButton(
        onPressed: () {
          if (!_formKey.currentState!.validate()) return;
          final value = double.parse(_controller.text.trim());
          Navigator.pop(context, value);
        },
        child: const Text('Save'),
      ),
    ],
  );
}

class _CreateItemDialog extends StatefulWidget {
  const _CreateItemDialog();

  @override
  State<_CreateItemDialog> createState() => _CreateItemDialogState();
}

class _CreateItemDialogState extends State<_CreateItemDialog> {
  final _formKey = GlobalKey<FormState>();
  final _sku = TextEditingController();
  final _name = TextEditingController();
  final _unit = TextEditingController(text: 'piece');
  final _quantity = TextEditingController(text: '0');
  final _threshold = TextEditingController(text: '5');
  final _reorder = TextEditingController(text: '10');

  @override
  void dispose() {
    _sku.dispose();
    _name.dispose();
    _unit.dispose();
    _quantity.dispose();
    _threshold.dispose();
    _reorder.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Add inventory item'),
    content: SizedBox(
      width: 420,
      child: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              _field(_sku, 'SKU'),
              _field(_name, 'Item name'),
              _field(_unit, 'Unit'),
              _field(_quantity, 'Starting quantity', numeric: true),
              _field(_threshold, 'Reorder threshold', numeric: true),
              _field(_reorder, 'Suggested reorder quantity', numeric: true),
            ],
          ),
        ),
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      FilledButton(onPressed: _save, child: const Text('Create')),
    ],
  );

  Widget _field(
    TextEditingController controller,
    String label, {
    bool numeric = false,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextFormField(
        controller: controller,
        keyboardType: numeric
            ? const TextInputType.numberWithOptions(decimal: true)
            : TextInputType.text,
        decoration: InputDecoration(
          labelText: label,
          border: const OutlineInputBorder(),
        ),
        validator: (value) {
          if (value == null || value.trim().isEmpty) {
            return 'Enter $label.';
          }
          if (numeric) {
            final parsed = double.tryParse(value);
            if (parsed == null) {
              return 'Enter a number.';
            }
            if (parsed < 0) {
              return 'Use zero or a positive number.';
            }
            if (label == 'Suggested reorder quantity' && parsed <= 0) {
              return 'Use a quantity greater than zero.';
            }
          }
          return null;
        },
      ),
    );
  }

  void _save() {
    if (!_formKey.currentState!.validate()) return;
    Navigator.pop(context, {
      'sku': _sku.text.trim(),
      'name': _name.text.trim(),
      'unit': _unit.text.trim(),
      'on_hand_quantity': double.parse(_quantity.text),
      'reorder_threshold': double.parse(_threshold.text),
      'suggested_reorder_quantity': double.parse(_reorder.text),
    });
  }
}
