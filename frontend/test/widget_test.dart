import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/api_client.dart';
import 'package:frontend/main.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('shows an empty inventory state when the API has no items', (
    tester,
  ) async {
    final client = MockClient((_) async {
      return http.Response(
        jsonEncode({'data': <dynamic>[]}),
        200,
        headers: {'content-type': 'application/json'},
      );
    });
    addTearDown(client.close);
    tester.view.physicalSize = const Size(1200, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      MaterialApp(
        home: InventoryHome(api: ApiClient(client: client)),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Inventory'), findsWidgets);
    expect(
      find.text('No inventory items yet. Add an item to begin.'),
      findsOneWidget,
    );
  });

  testWidgets('shows the planned forbidden-access state', (tester) async {
    final client = MockClient((_) async {
      return http.Response(
        jsonEncode({'message': 'Forbidden'}),
        403,
        headers: {'content-type': 'application/json'},
      );
    });
    addTearDown(client.close);

    await tester.pumpWidget(
      MaterialApp(
        home: InventoryHome(api: ApiClient(client: client)),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.textContaining('403: Forbidden'), findsOneWidget);
    expect(
      find.textContaining(
        'Authentication and role enforcement are scheduled for Week 14.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('shows a service-unavailable recovery state', (tester) async {
    final client = MockClient((_) async {
      return http.Response(
        jsonEncode({'message': 'Service unavailable'}),
        503,
        headers: {'content-type': 'application/json'},
      );
    });
    addTearDown(client.close);

    await tester.pumpWidget(
      MaterialApp(
        home: InventoryHome(api: ApiClient(client: client)),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Service unavailable'), findsOneWidget);
    expect(find.text('Retry'), findsOneWidget);
  });

  group('network failures versus API error responses', () {
    const networkMessage =
        'Cannot reach the API server. Check that the Laravel backend is running and reachable, then try again.';
    final item = {
      'id': 7,
      'sku': 'SKU-7',
      'name': 'Widget',
      'unit': 'piece',
      'on_hand_quantity': '10.000',
      'reorder_threshold': '5.000',
      'below_threshold': false,
    };

    http.Response json(Object body, int status) => http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

    Future<void> openIssueAndSave(WidgetTester tester) async {
      await tester.tap(find.text('Issue'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), '3');
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
    }

    test(
      'connection failures are reported as network errors for every verb',
      () async {
        final client = MockClient((_) async {
          throw http.ClientException('Connection refused');
        });
        final api = ApiClient(client: client);

        final calls = <String, Future<void> Function()>{
          'GET': api.getItems,
          'POST': () => api.reviewReorder(1, 'approve'),
          'PATCH': () => api.markOrdered(1),
        };
        for (final entry in calls.entries) {
          await expectLater(
            entry.value(),
            throwsA(
              isA<ApiException>()
                  .having(
                    (e) => e.isNetworkError,
                    '${entry.key} isNetworkError',
                    true,
                  )
                  .having((e) => e.statusCode, 'statusCode', 0)
                  .having((e) => e.message, 'message', networkMessage),
            ),
          );
        }
      },
    );

    test('HTTP error responses stay distinct from network errors', () async {
      final cases = <int, Map<String, Object>>{
        404: {'message': 'Inventory item not found.'},
        409: {'message': 'Only approved requests can be marked ordered.'},
        422: {
          'message': 'The selected decision is invalid.',
          'errors': {
            'decision': ['The selected decision is invalid.'],
          },
        },
        500: {'message': 'Server Error'},
      };
      for (final entry in cases.entries) {
        final api = ApiClient(
          client: MockClient((_) async => json(entry.value, entry.key)),
        );
        await expectLater(
          api.markOrdered(1),
          throwsA(
            isA<ApiException>()
                .having(
                  (e) => e.isNetworkError,
                  '${entry.key} isNetworkError',
                  false,
                )
                .having((e) => e.statusCode, 'statusCode', entry.key)
                .having((e) => e.message, 'message', entry.value['message']),
          ),
        );
      }
    });

    testWidgets(
      'load failure shows the connection message, stale-data banner and Retry',
      (tester) async {
        var online = true;
        final client = MockClient((_) async {
          if (!online) throw http.ClientException('Failed to fetch');
          return json({
            'data': [item],
          }, 200);
        });
        addTearDown(client.close);
        tester.view.physicalSize = const Size(1200, 900);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        await tester.pumpWidget(
          MaterialApp(
            home: InventoryHome(api: ApiClient(client: client)),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('Widget'), findsOneWidget);

        online = false;
        await tester.tap(find.byTooltip('Refresh'));
        await tester.pumpAndSettle();

        expect(find.text('Widget'), findsOneWidget);
        expect(find.textContaining(networkMessage), findsWidgets);
        expect(find.textContaining('MySQL'), findsNothing);
        expect(find.textContaining('MariaDB'), findsNothing);
        expect(find.text('Retry'), findsOneWidget);

        online = true;
        await tester.tap(find.text('Retry'));
        await tester.pumpAndSettle();
        expect(find.text('Retry'), findsNothing);
        expect(find.text('Widget'), findsOneWidget);
      },
    );

    testWidgets('a write that fails on the network never reports success', (
      tester,
    ) async {
      final posts = <String>[];
      final client = MockClient((request) async {
        if (request.method == 'POST') {
          posts.add(request.url.path);
          throw http.ClientException('Connection refused');
        }
        return json({
          'data': [item],
        }, 200);
      });
      addTearDown(client.close);
      tester.view.physicalSize = const Size(1200, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(
        MaterialApp(
          home: InventoryHome(api: ApiClient(client: client)),
        ),
      );
      await tester.pumpAndSettle();
      await openIssueAndSave(tester);

      expect(posts, ['/api/v1/items/7/transactions']);
      expect(find.text(networkMessage), findsOneWidget);
      expect(find.text('Stock transaction recorded.'), findsNothing);
      expect(find.textContaining('MySQL'), findsNothing);
    });

    testWidgets(
      'a write rejected with HTTP 422 shows the API message, not a network message',
      (tester) async {
        final client = MockClient((request) async {
          if (request.method == 'POST') {
            return json({
              'message': 'The transaction would make stock negative.',
              'errors': {
                'quantity': ['The transaction would make stock negative.'],
              },
            }, 422);
          }
          return json({
            'data': [item],
          }, 200);
        });
        addTearDown(client.close);
        tester.view.physicalSize = const Size(1200, 900);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        await tester.pumpWidget(
          MaterialApp(
            home: InventoryHome(api: ApiClient(client: client)),
          ),
        );
        await tester.pumpAndSettle();
        await openIssueAndSave(tester);

        expect(
          find.text('The transaction would make stock negative.'),
          findsOneWidget,
        );
        expect(
          find.textContaining('Cannot reach the API server'),
          findsNothing,
        );
        expect(find.text('Stock transaction recorded.'), findsNothing);
      },
    );

    testWidgets('a non-network programming error gets a neutral message', (
      tester,
    ) async {
      final client = MockClient(
        (_) async => json({
          'data': [1, 2],
        }, 200),
      );
      addTearDown(client.close);

      await tester.pumpWidget(
        MaterialApp(
          home: InventoryHome(api: ApiClient(client: client)),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.textContaining('Unexpected error while handling the API response'),
        findsOneWidget,
      );
      expect(find.textContaining('Cannot reach the API server'), findsNothing);
      expect(find.textContaining('MySQL'), findsNothing);
    });
  });
}
