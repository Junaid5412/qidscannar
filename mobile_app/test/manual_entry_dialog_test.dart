import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qid_scanner/widgets/manual_entry_dialog.dart';

void main() {
  /// A stand-in for the scanner screen that opens the dialog the same way it does.
  Future<List<String>> openDialog(WidgetTester tester) async {
    final submitted = <String>[];
    await tester.pumpWidget(MaterialApp(
      home: Builder(
        builder: (context) => Scaffold(
          body: Column(children: [
            const Text('SCANNER SCREEN'),
            ElevatedButton(
              onPressed: () => showDialog(
                context: context,
                builder: (_) => ManualEntryDialog(onSubmit: submitted.add),
              ),
              child: const Text('open'),
            ),
          ]),
        ),
      ),
    ));
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    return submitted;
  }

  testWidgets('a valid QID is sent, the dialog closes and the scanner screen stays open', (tester) async {
    final submitted = await openDialog(tester);
    await tester.enterText(find.byType(TextField), '28535603456');
    await tester.tap(find.text('Push to Desktop'));
    await tester.pumpAndSettle();

    expect(submitted, ['28535603456']);
    expect(find.byType(ManualEntryDialog), findsNothing);
    expect(find.text('SCANNER SCREEN'), findsOneWidget);
  });

  testWidgets('too short, wrong first digit and impossible numbers are refused with a reason', (tester) async {
    final submitted = await openDialog(tester);

    await tester.enterText(find.byType(TextField), '2853560345');
    await tester.tap(find.text('Push to Desktop'));
    await tester.pump();
    expect(find.textContaining('you entered 10'), findsOneWidget);

    await tester.enterText(find.byType(TextField), '58535603456');
    await tester.tap(find.text('Push to Desktop'));
    await tester.pump();
    expect(find.textContaining('starts with 2 or 3'), findsOneWidget);

    expect(submitted, isEmpty);
    expect(find.byType(ManualEntryDialog), findsOneWidget);
  });

  testWidgets('letters and spaces cannot be typed', (tester) async {
    await openDialog(tester);
    await tester.enterText(find.byType(TextField), '285 35a603456');
    await tester.pump();
    expect(tester.widget<TextField>(find.byType(TextField)).controller!.text, '28535603456');
  });

  testWidgets('Cancel closes only the dialog', (tester) async {
    final submitted = await openDialog(tester);
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();
    expect(submitted, isEmpty);
    expect(find.text('SCANNER SCREEN'), findsOneWidget);
  });
}
