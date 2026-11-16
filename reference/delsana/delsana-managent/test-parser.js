// Test script for parse-customer.js
const { parseCustomerText } = require('./parse-customer.js');

function test(name, text, expected) {
  console.log('=== ' + name + ' ===');
  var r = parseCustomerText(text);
  console.log(JSON.stringify(r, null, 2));
  
  // Check key fields
  var pass = true;
  if (r.name !== expected.name) { console.log('FAIL: name expected "' + expected.name + '" got "' + r.name + '"'); pass = false; }
  if (r.phone !== expected.phone) { console.log('FAIL: phone expected "' + expected.phone + '" got "' + r.phone + '"'); pass = false; }
  if (r.postalCode !== expected.postalCode) { console.log('FAIL: postalCode expected "' + expected.postalCode + '" got "' + r.postalCode + '"'); pass = false; }
  if (r.address !== expected.address) { console.log('FAIL: address expected "' + expected.address + '" got "' + r.address + '"'); pass = false; }
  if (r.province !== expected.province) { console.log('FAIL: province expected "' + expected.province + '" got "' + r.province + '"'); pass = false; }
  if (r.warnings.length !== expected.warnings.length) { console.log('FAIL: warnings length expected ' + expected.warnings.length + ' got ' + r.warnings.length); pass = false; }
  else {
    for (var i = 0; i < r.warnings.length; i++) {
      if (r.warnings[i] !== expected.warnings[i]) { console.log('FAIL: warning ' + i + ' expected "' + expected.warnings[i] + '" got "' + r.warnings[i] + '"'); pass = false; }
    }
  }
  if (pass) console.log('PASS');
  console.log('');
}

// Sample 1 (unlabeled, line by line)
test('Sample 1', 
  'سارا محمدی\n09121234567\nمازندران، شهرستان ساری، جاده نظامی، روستای نمونه\nکد پستی: 4812345678',
  { name: 'سارا محمدی', phone: '09121234567', postalCode: '4812345678', address: 'مازندران، شهرستان ساری، جاده نظامی، روستای نمونه', province: 'مازندران', warnings: [] }
);

// Sample 2 (labeled with emojis, no space after colon)
test('Sample 2', 
  '👤 نام و نام خانوادگی: علی رضایی\n📞 شماره تماس:09131234567\n📍 آدرس پستی:زاهدان سی متری کوثر-کوثر۱۱ Nbش بن بست6متری\n📮 کد پستی:9812345678',
  { name: 'علی رضایی', phone: '09131234567', postalCode: '9812345678', address: 'زاهدان سی متری کوثر-کوثر۱۱ Nbش بن بست6متری', province: 'سیستان و بلوچستان', warnings: [] }
);

// Sample 3 (unlabeled, postal code written with spaces on the last line)
test('Sample 3', 
  'رضا کریمی\n09141234567\nبوشهر برازجان خیابان مجاهد خیابان لاله\n756 177 7797',
  { name: 'رضا کریمی', phone: '09141234567', postalCode: '7561777797', address: 'بوشهر برازجان خیابان مجاهد خیابان لاله', province: 'بوشهر', warnings: [] }
);

// Extra: Persian digits
test('Persian digits',
  'سیدا محمدی\n۰۹۱۲۱۲۳۴۵۶۷\n تهران، خیابان ولیاعرíso، پلاک ۵\n۰۸۲۳۴۵۶۷۸۹',
  { name: 'سیدا محمدی', phone: '09121234567', postalCode: '0823456789', address: 'تهران، خیابان ولیاعرíso، پلاک ۵', province: undefined, warnings: ['کد پستی ۱۰ رقمی نیست'] } // postal 0823456789 has leading 0
);

// Extra: phone as +98 912 123 4567
test('+98 912 123 4567',
  'علی رضا\n+98 912 123 4567\nآدرهemploytest\nکد پستی: 1234567890',
  { name: 'علی رضا', phone: '09121234567', postalCode: '1234567890', address: 'آدرهemploytest', province: undefined, warnings: [] }
);

// Extra: empty
test('Empty string',
  '',
  { name: '', phone: '', postalCode: '', address: '', province: undefined, warnings: ['کد پستی پیدا نشد'] }
);

// Extra: missing name
test('Missing name',
  '09121234567\nکد پستی: 4812345678',
  { name: '', phone: '09121234567', postalCode: '4812345678', address: '', province: undefined, warnings: ['نام پیدا نشد'] }
);

// Extra: missing postal code
test('Missing postal',
  'علی حسن\n09131234567',
  { name: 'علی حسن', phone: '09131234567', postalCode: '', address: '', province: undefined, warnings: ['کد پستی پیدا نشد'] }
);