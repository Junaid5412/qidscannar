/// Reads the Qatar ID number and card details out of barcode values and OCR text.
///
/// A QID is 11 digits:  C YY NNN SSSSS
///   C     century of birth: 2 = 1900s, 3 = 2000s
///   YY    year of birth
///   NNN   nationality (ISO 3166-1 numeric, e.g. 356 India, 586 Pakistan, 634 Qatar)
///   SSSSS serial
/// Checking that shape rejects phone numbers, card serials and OCR misreads
/// that happen to be 11 digits long.
class QidParser {
  QidParser._();

  /// 11 digits starting with 2 or 3, not part of a longer number.
  static final RegExp _qidRun = RegExp(r'(?<!\d)([23]\d{10})(?!\d)');

  /// A run of digits that may contain single spaces / dashes (e.g. "285 356 03456")
  /// or OCR look-alikes (O for 0, I / l / | for 1).
  static final RegExp _looseRun = RegExp(r'(?<![0-9A-Za-z])([0-9OoIl|][0-9OoIl| \-]{9,16}[0-9OoIl|])(?![0-9A-Za-z])');

  static bool isValid(String qid, {DateTime? now}) {
    if (!RegExp(r'^[23]\d{10}$').hasMatch(qid)) return false;
    final birthYear = (qid[0] == '2' ? 1900 : 2000) + int.parse(qid.substring(1, 3));
    if (birthYear > (now ?? DateTime.now()).year) return false;
    return qid.substring(3, 6) != '000';
  }

  /// Why a typed number is not a QID, or null when it is one (manual entry).
  static String? problem(String input, {DateTime? now}) {
    final digits = input.trim();
    if (digits.isEmpty) return 'Enter the 11-digit Qatar ID number.';
    if (!RegExp(r'^\d+$').hasMatch(digits)) return 'Use digits only.';
    if (digits.length != 11) return 'A Qatar ID has 11 digits (you entered ${digits.length}).';
    if (digits[0] != '2' && digits[0] != '3') return 'A Qatar ID starts with 2 or 3.';
    if (!isValid(digits, now: now)) return 'This is not a valid Qatar ID number. Check the digits.';
    return null;
  }

  /// The QID inside a barcode value: the bare number (spaces / dashes allowed),
  /// or a QID carried among other data.
  static String? fromBarcode(String raw, {DateTime? now}) {
    final whole = raw.trim().replaceAll(RegExp(r'[\s\-]'), '');
    if (isValid(whole, now: now)) return whole;
    for (final m in _qidRun.allMatches(raw)) {
      if (isValid(m.group(1)!, now: now)) return m.group(1);
    }
    return null;
  }

  /// The QID in the text lines read from the card. A number on (or right after)
  /// an "ID No" label wins; otherwise the first valid QID anywhere.
  static String? fromText(List<String> lines, {DateTime? now}) {
    final labelled = <String>[];
    for (var i = 0; i < lines.length; i++) {
      if (RegExp(r'\bI\.?\s?D\b|\bID\s*\.?\s*NO\b', caseSensitive: false).hasMatch(lines[i])) {
        labelled.add(lines[i]);
        if (i + 1 < lines.length) labelled.add(lines[i + 1]);
      }
    }
    for (final group in [labelled, lines]) {
      for (final line in group) {
        final qid = _qidInLine(line, now: now);
        if (qid != null) return qid;
      }
    }
    return null;
  }

  static String? _qidInLine(String line, {DateTime? now}) {
    for (final m in _qidRun.allMatches(line)) {
      if (isValid(m.group(1)!, now: now)) return m.group(1);
    }
    for (final m in _looseRun.allMatches(line)) {
      final candidate = m.group(1)!
          .replaceAll(RegExp(r'[ \-]'), '')
          .replaceAll(RegExp(r'[Oo]'), '0')
          .replaceAll(RegExp(r'[Il|]'), '1');
      // only trust a look-alike fix when most of it was real digits
      final realDigits = RegExp(r'\d').allMatches(m.group(1)!).length;
      if (realDigits >= 9 && isValid(candidate, now: now)) return candidate;
    }
    return null;
  }

  /// Card expiry as yyyy-mm-dd: the date on (or after) an "Expiry" label,
  /// otherwise the latest date on the card (expiry is always after birth and issue).
  static String? expiryFrom(List<String> lines) {
    DateTime? labelled;
    DateTime? latest;
    for (var i = 0; i < lines.length; i++) {
      final isLabel = RegExp(r'EXP', caseSensitive: false).hasMatch(lines[i]);
      final sameLine = _dates(lines[i]);
      final dates = [...sameLine, if (isLabel && sameLine.isEmpty && i + 1 < lines.length) ..._dates(lines[i + 1])];
      for (final d in dates) {
        if (isLabel && labelled == null) labelled = d;
        if (latest == null || d.isAfter(latest)) latest = d;
      }
    }
    final pick = labelled ?? latest;
    if (pick == null || pick.year < 2000) return null;
    return '${pick.year.toString().padLeft(4, '0')}-${pick.month.toString().padLeft(2, '0')}-${pick.day.toString().padLeft(2, '0')}';
  }

  static List<DateTime> _dates(String line) {
    final out = <DateTime>[];
    for (final m in RegExp(r'(?<!\d)(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})(?!\d)').allMatches(line)) {
      final d = _date(int.parse(m.group(3)!), int.parse(m.group(2)!), int.parse(m.group(1)!));
      if (d != null) out.add(d);
    }
    for (final m in RegExp(r'(?<!\d)(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})(?!\d)').allMatches(line)) {
      final d = _date(int.parse(m.group(1)!), int.parse(m.group(2)!), int.parse(m.group(3)!));
      if (d != null) out.add(d);
    }
    return out;
  }

  static DateTime? _date(int y, int m, int d) {
    if (y < 1900 || y > 2100 || m < 1 || m > 12 || d < 1 || d > 31) return null;
    final dt = DateTime(y, m, d);
    return (dt.month == m && dt.day == d) ? dt : null;   // rejects 31/02 etc.
  }

  /// Words that appear on a Qatar ID but are never the holder's name.
  static const List<String> _notName = [
    'STATE', 'QATAR', 'RESIDENCY', 'RESIDENCE', 'PERMIT', 'IDENTITY', 'CARD', 'NATIONALITY', 'OCCUPATION',
    'EXPIRY', 'BIRTH', 'PASSPORT', 'SERIAL', 'EMPLOYER', 'DIRECTOR', 'GENERAL', 'SIGNATURE', 'BLOOD',
    'MINISTRY', 'INTERIOR', 'DATE', 'COMPANY', 'CO.', 'W.L.L', 'WLL', 'LLC', 'TRADING', 'CONTRACTING',
    'SERVICES', 'GROUP', 'HOLDING', 'ESTABLISHMENT', 'EST.', 'GOVERNMENT', 'AUTHORITY',
  ];

  /// The holder's name: the text after a "Name" label (or on the next line),
  /// otherwise the first all-capitals line of two or more plain words.
  static String? nameFrom(List<String> lines) {
    for (var i = 0; i < lines.length; i++) {
      final m = RegExp(r'^\s*NAME\b\s*[:\-.]?\s*(.*)$', caseSensitive: false).firstMatch(lines[i]);
      if (m == null) continue;
      final rest = _cleanName(m.group(1)!);
      if (rest != null) return rest;
      if (i + 1 < lines.length) {
        final next = _cleanName(lines[i + 1]);
        if (next != null) return next;
      }
    }
    for (final line in lines) {
      final t = line.trim();
      if (!RegExp(r"^[A-Z][A-Z'\-]*(\s+[A-Z][A-Z'\-]*)+$").hasMatch(t)) continue;   // capitals, 2+ words
      if (t.split(RegExp(r'\s+')).any(_notName.contains)) continue;   // whole words: RICARDO is not CARD
      if (_countries.values.contains(t)) continue;
      final name = _cleanName(t);
      if (name != null) return name;
    }
    return null;
  }

  static String? _cleanName(String s) {
    final t = s.trim().replaceAll(RegExp(r'\s+'), ' ');
    if (t.length < 3 || !RegExp(r"^[A-Za-z][A-Za-z '\-.]+$").hasMatch(t)) return null;
    return t.toUpperCase();
  }

  /// Nationality from the QID's own digits (most reliable), else from the card text.
  static String? nationality(String? qid, List<String> lines) {
    if (qid != null && qid.length == 11) {
      final byCode = _countries[qid.substring(3, 6)];
      if (byCode != null) return byCode;
    }
    String? best;
    for (final line in lines) {
      final upper = line.toUpperCase();
      for (final c in _countries.values) {
        if (RegExp('\\b${RegExp.escape(c)}\\b').hasMatch(upper) && (best == null || c.length > best.length)) best = c;
      }
    }
    return best;
  }

  /// ISO 3166-1 numeric codes of the nationalities most common in Qatar.
  static const Map<String, String> _countries = {
    '004': 'AFGHANISTAN', '012': 'ALGERIA', '036': 'AUSTRALIA', '048': 'BAHRAIN', '050': 'BANGLADESH',
    '076': 'BRAZIL', '124': 'CANADA', '144': 'SRI LANKA', '156': 'CHINA', '231': 'ETHIOPIA', '232': 'ERITREA',
    '250': 'FRANCE', '275': 'PALESTINE', '276': 'GERMANY', '288': 'GHANA', '356': 'INDIA', '360': 'INDONESIA',
    '364': 'IRAN', '368': 'IRAQ', '380': 'ITALY', '400': 'JORDAN', '404': 'KENYA', '410': 'SOUTH KOREA',
    '414': 'KUWAIT', '422': 'LEBANON', '434': 'LIBYA', '458': 'MALAYSIA', '504': 'MOROCCO', '512': 'OMAN',
    '524': 'NEPAL', '566': 'NIGERIA', '586': 'PAKISTAN', '608': 'PHILIPPINES', '634': 'QATAR',
    '643': 'RUSSIA', '682': 'SAUDI ARABIA', '706': 'SOMALIA', '710': 'SOUTH AFRICA', '724': 'SPAIN',
    '728': 'SOUTH SUDAN', '729': 'SUDAN', '760': 'SYRIA', '764': 'THAILAND', '788': 'TUNISIA', '792': 'TURKEY',
    '800': 'UGANDA', '784': 'UNITED ARAB EMIRATES', '804': 'UKRAINE', '818': 'EGYPT', '826': 'UNITED KINGDOM',
    '840': 'UNITED STATES', '704': 'VIETNAM', '887': 'YEMEN', '894': 'ZAMBIA', '716': 'ZIMBABWE',
    '430': 'LIBERIA', '694': 'SIERRA LEONE', '108': 'BURUNDI', '646': 'RWANDA', '834': 'TANZANIA',
    '180': 'CONGO', '120': 'CAMEROON', '686': 'SENEGAL', '478': 'MAURITANIA', '462': 'MALDIVES',
    '104': 'MYANMAR', '064': 'BHUTAN',
  };
}
