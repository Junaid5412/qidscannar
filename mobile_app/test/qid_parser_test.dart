import 'package:flutter_test/flutter_test.dart';
import 'package:qid_scanner/services/qid_parser.dart';

/// Reading a Qatar ID in the situations the scanner meets: clean and damaged barcodes,
/// OCR of the front and back of the card, look-alike characters, and numbers that are not QIDs.
void main() {
  final now = DateTime(2026, 11, 3);

  group('isValid', () {
    test('real QIDs pass', () {
      expect(QidParser.isValid('28535603456', now: now), isTrue);   // 1985, India
      expect(QidParser.isValid('30158609775', now: now), isTrue);   // 2001, Pakistan
      expect(QidParser.isValid('29063400001', now: now), isTrue);   // 1990, Qatar
    });
    test('wrong length, wrong century digit, future birth year, empty nationality fail', () {
      expect(QidParser.isValid('2853560345', now: now), isFalse);
      expect(QidParser.isValid('285356034567', now: now), isFalse);
      expect(QidParser.isValid('18535603456', now: now), isFalse);
      expect(QidParser.isValid('97455123456', now: now), isFalse);  // +974 phone number
      expect(QidParser.isValid('39935603456', now: now), isFalse);  // born 2099
      expect(QidParser.isValid('28500003456', now: now), isFalse);  // nationality 000
      expect(QidParser.isValid('2853560345a', now: now), isFalse);
    });
  });

  group('manual entry problem()', () {
    test('explains each mistake', () {
      expect(QidParser.problem('', now: now), contains('11-digit'));
      expect(QidParser.problem('2853560345', now: now), contains('you entered 10'));
      expect(QidParser.problem('2853 5603456', now: now), contains('digits only'));
      expect(QidParser.problem('58535603456', now: now), contains('starts with 2 or 3'));
      expect(QidParser.problem('39935603456', now: now), contains('not a valid'));
      expect(QidParser.problem(' 28535603456 ', now: now), isNull);
    });
  });

  group('fromBarcode', () {
    test('bare number', () => expect(QidParser.fromBarcode('28535603456', now: now), '28535603456'));
    test('with spaces or dashes', () {
      expect(QidParser.fromBarcode('285 356 03456', now: now), '28535603456');
      expect(QidParser.fromBarcode('285-356-03456', now: now), '28535603456');
    });
    test('QID carried among other data', () {
      expect(QidParser.fromBarcode('QID:28535603456|EXP:2027-06-07', now: now), '28535603456');
      expect(QidParser.fromBarcode('ID 30158609775 SERIAL 0012345', now: now), '30158609775');
    });
    test('rejects phone numbers, serials and partial numbers', () {
      expect(QidParser.fromBarcode('+974 5512 3456', now: now), isNull);
      expect(QidParser.fromBarcode('123456789012', now: now), isNull);
      expect(QidParser.fromBarcode('2853560345', now: now), isNull);
      expect(QidParser.fromBarcode('A1B2C3', now: now), isNull);
      expect(QidParser.fromBarcode('285356034561', now: now), isNull);   // 12 digits: not cut down to 11
    });
  });

  group('fromText (OCR)', () {
    test('front of card with ID label', () {
      final lines = ['State of Qatar', 'Residency Permit', 'ID. No: 28535603456', 'D.O.B: 12/05/1985', 'Expiry: 07/06/2027', 'Nationality: INDIA'];
      expect(QidParser.fromText(lines, now: now), '28535603456');
    });
    test('number on the line after the label', () {
      expect(QidParser.fromText(['ID No.', '30158609775'], now: now), '30158609775');
    });
    test('digits split by spaces', () {
      expect(QidParser.fromText(['ID No 285 356 03456'], now: now), '28535603456');
    });
    test('OCR look-alikes O and l are corrected', () {
      expect(QidParser.fromText(['ID No: 2853560345O'], now: now), '28535603450');
      expect(QidParser.fromText(['ID No: 285356O3456'], now: now), '28535603456');
      expect(QidParser.fromText(['ID No: 2853560345l'], now: now), '28535603451');
    });
    test('the labelled QID wins over another valid-looking number', () {
      final lines = ['Serial 29935600001', 'ID. No: 28535603456'];
      expect(QidParser.fromText(lines, now: now), '28535603456');
    });
    test('ignores serial numbers, phone numbers and long runs (old code took their last 11 digits)', () {
      expect(QidParser.fromText(['SERIAL NO 0123456789012'], now: now), isNull);
      expect(QidParser.fromText(['Tel +974 55123456'], now: now), isNull);
      expect(QidParser.fromText(['AB1234567890123'], now: now), isNull);
      expect(QidParser.fromText(['QATAR 2026'], now: now), isNull);
    });
    test('mostly letters is not turned into a number', () {
      expect(QidParser.fromText(['ID No: lOlOlOlOlOl'], now: now), isNull);
    });
  });

  group('expiryFrom', () {
    test('labelled expiry wins over birth date', () {
      expect(QidParser.expiryFrom(['D.O.B: 12/05/2001', 'Expiry: 07/06/2027']), '2027-06-07');
    });
    test('label on one line, date on the next', () {
      expect(QidParser.expiryFrom(['Expiry Date', '31-12-2026']), '2026-12-31');
    });
    test('no label: the latest date is the expiry', () {
      expect(QidParser.expiryFrom(['12/05/2021', '07/06/2027', '01/01/2024']), '2027-06-07');
    });
    test('year-first and dotted formats', () {
      expect(QidParser.expiryFrom(['Expiry 2027/06/07']), '2027-06-07');
      expect(QidParser.expiryFrom(['Expiry 7.6.2027']), '2027-06-07');
    });
    test('impossible dates and old dates are ignored', () {
      expect(QidParser.expiryFrom(['Expiry 31/02/2027']), isNull);
      expect(QidParser.expiryFrom(['D.O.B 12/05/1985']), isNull);
      expect(QidParser.expiryFrom(['no date here']), isNull);
    });
  });

  group('nameFrom', () {
    test('Name label on the same line', () {
      expect(QidParser.nameFrom(['Name: Rajesh Kumar Patel']), 'RAJESH KUMAR PATEL');
    });
    test('Name label then the name on the next line', () {
      expect(QidParser.nameFrom(['Name', 'JUNAID SHAHEEN MUHAMMAD SHAHBAZ']), 'JUNAID SHAHEEN MUHAMMAD SHAHBAZ');
    });
    test('unlabelled capitals line, skipping card titles, countries and employers', () {
      final lines = ['STATE OF QATAR', 'RESIDENCY PERMIT', 'INDIA', 'SRI LANKA', 'DOHA EXPRESS TRADING W.L.L', 'RICARDO SANTOS'];
      expect(QidParser.nameFrom(lines), 'RICARDO SANTOS');
    });
    test('nothing usable', () {
      expect(QidParser.nameFrom(['12345', 'ID No 28535603456', 'single']), isNull);
    });
  });

  group('nationality', () {
    test('taken from the QID digits', () {
      expect(QidParser.nationality('28535603456', const []), 'INDIA');
      expect(QidParser.nationality('30158609775', const []), 'PAKISTAN');
      expect(QidParser.nationality('29063400001', const []), 'QATAR');
    });
    test('falls back to the card text, longest match wins', () {
      expect(QidParser.nationality('28599903456', ['Nationality: SRI LANKA']), 'SRI LANKA');
      expect(QidParser.nationality(null, ['SOUTH SUDAN']), 'SOUTH SUDAN');
      expect(QidParser.nationality(null, ['INDIANA']), isNull);   // whole words only
    });
  });
}
