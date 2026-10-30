import 'dart:convert';

import 'package:http/http.dart' as http;

const _apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://127.0.0.1:8000/api/v1',
);

class ApiException implements Exception {
  const ApiException(this.statusCode, this.message);

  /// The request never produced an HTTP response (server down, refused
  /// connection, DNS failure, blocked by the browser, ...).
  const ApiException.network()
    : statusCode = networkErrorStatus,
      message =
          'Cannot reach the API server. Check that the Laravel backend is running and reachable, then try again.';

  static const networkErrorStatus = 0;

  final int statusCode;
  final String message;

  bool get isNetworkError => statusCode == networkErrorStatus;

  bool get isAuthorizationError => statusCode == 401 || statusCode == 403;

  @override
  String toString() => message;
}

class ApiClient {
  ApiClient({http.Client? client})
    : _client = client ?? http.Client(),
      _baseUri = Uri.parse(
        _apiBaseUrl.endsWith('/') ? _apiBaseUrl : '$_apiBaseUrl/',
      );

  final http.Client _client;
  final Uri _baseUri;

  Future<List<Map<String, dynamic>>> getItems() => _getList('/items');

  Future<List<Map<String, dynamic>>> getAlerts() => _getList('/alerts');

  Future<List<Map<String, dynamic>>> getReorders() =>
      _getList('/reorder-requests');

  Future<List<Map<String, dynamic>>> getWeeklySummaries() =>
      _getList('/stock-risk/weekly');

  Future<List<Map<String, dynamic>>> getManualActions() =>
      _getList('/manual-actions');

  Future<void> createItem(Map<String, dynamic> data) => _post('/items', data);

  Future<void> recordTransaction(int itemId, String type, double quantity) =>
      _post('/items/$itemId/transactions', {
        'type': type,
        'quantity': quantity,
      });

  Future<void> acknowledgeAlert(int alertId) =>
      _post('/alerts/$alertId/acknowledgement', const {});

  Future<void> reviewReorder(int requestId, String decision) =>
      _post('/reorder-requests/$requestId/review', {'decision': decision});

  Future<void> markOrdered(int requestId) => _patch(
    '/reorder-requests/$requestId/procurement-status',
    {'status': 'ordered'},
  );

  Future<void> receiveReorder(int requestId, double quantity) =>
      _post('/reorder-requests/$requestId/receipts', {'quantity': quantity});

  Future<void> closeReorder(int requestId, String note) =>
      _post('/reorder-requests/$requestId/close', {'resolution_note': note});

  Future<void> resolveManualAction(int actionId, String note) =>
      _post('/manual-actions/$actionId/resolve', {'resolution_note': note});

  Future<List<Map<String, dynamic>>> _getList(String path) async {
    final response = await _send(
      () => _client.get(_uri(path), headers: _headers()),
    );
    final data = _decode(response);
    if (data is! List) {
      throw const ApiException(
        500,
        'The API returned an unexpected list response.',
      );
    }

    return data.map((item) => Map<String, dynamic>.from(item as Map)).toList();
  }

  Future<void> _post(String path, Map<String, dynamic> body) async {
    final response = await _send(
      () =>
          _client.post(_uri(path), headers: _headers(), body: jsonEncode(body)),
    );
    _decode(response);
  }

  Future<void> _patch(String path, Map<String, dynamic> body) async {
    final response = await _send(
      () => _client.patch(
        _uri(path),
        headers: _headers(),
        body: jsonEncode(body),
      ),
    );
    _decode(response);
  }

  // package:http wraps socket, TLS and browser fetch failures in ClientException.
  Future<http.Response> _send(Future<http.Response> Function() request) async {
    try {
      return await request();
    } on http.ClientException {
      throw const ApiException.network();
    }
  }

  Uri _uri(String path) => _baseUri.resolve(path.replaceFirst('/', ''));

  Map<String, String> _headers() => {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Correlation-ID': 'web-${DateTime.now().microsecondsSinceEpoch}',
  };

  dynamic _decode(http.Response response) {
    dynamic body;
    if (response.body.isNotEmpty) {
      try {
        body = jsonDecode(response.body);
      } on FormatException {
        body = null;
      }
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      final message = body is Map
          ? _errorMessage(body)
          : 'The server returned HTTP ${response.statusCode}.';
      throw ApiException(response.statusCode, message);
    }
    if (body is Map && body.containsKey('data')) {
      return body['data'];
    }

    return body;
  }

  String _errorMessage(Map<dynamic, dynamic> body) {
    final message = body['message'];
    if (message is String && message.isNotEmpty) {
      final errors = body['errors'];
      if (errors is Map && errors.isNotEmpty) {
        final firstError = errors.values.first;
        if (firstError is List && firstError.isNotEmpty) {
          return firstError.first.toString();
        }
      }
      return message;
    }

    final error = body['error'];
    if (error is Map && error['message'] is String) {
      return error['message'] as String;
    }

    return 'The server returned an error.';
  }
}
