/**
 * tests/review-helpers.test.js
 *
 * Unit tests for the pure helper functions in js/review-helpers.js
 * and integration tests for populateReview() using jsdom.
 *
 * Run:  npm test
 */

'use strict';

const { escHtml, rvRow, rvVal, kidReviewCard } = require('../js/review-helpers');

// ─────────────────────────────────────────────────────────────────────────────
// escHtml
// ─────────────────────────────────────────────────────────────────────────────
describe('escHtml()', () => {
  test('leaves plain text unchanged', () => {
    expect(escHtml('Hello world')).toBe('Hello world');
  });

  test('escapes ampersand', () => {
    expect(escHtml('A & B')).toBe('A &amp; B');
  });

  test('escapes less-than', () => {
    expect(escHtml('<script>')).toBe('&lt;script&gt;');
  });

  test('escapes greater-than', () => {
    expect(escHtml('3 > 2')).toBe('3 &gt; 2');
  });

  test('escapes double-quotes', () => {
    expect(escHtml('"quoted"')).toBe('&quot;quoted&quot;');
  });

  test('escapes all special chars in one string', () => {
    expect(escHtml('<a href="x">A & B</a>')).toBe('&lt;a href=&quot;x&quot;&gt;A &amp; B&lt;/a&gt;');
  });

  test('coerces non-string to string', () => {
    expect(escHtml(42)).toBe('42');
    expect(escHtml(null)).toBe('null');
    expect(escHtml(undefined)).toBe('undefined');
  });

  test('returns empty string for empty input', () => {
    expect(escHtml('')).toBe('');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// rvRow
// ─────────────────────────────────────────────────────────────────────────────
describe('rvRow()', () => {
  test('returns empty string when value is empty', () => {
    expect(rvRow('Name', '')).toBe('');
  });

  test('returns empty string when value is whitespace only', () => {
    expect(rvRow('Name', '   ')).toBe('');
  });

  test('returns empty string when value is null/undefined', () => {
    expect(rvRow('Name', null)).toBe('');
    expect(rvRow('Name', undefined)).toBe('');
  });

  test('wraps label in dt and value in dd', () => {
    const html = rvRow('Email', 'test@example.com');
    expect(html).toContain('<dt');
    expect(html).toContain('Email');
    expect(html).toContain('<dd');
    expect(html).toContain('test@example.com');
  });

  test('escapes HTML in label', () => {
    const html = rvRow('<b>Name</b>', 'Fred');
    expect(html).toContain('&lt;b&gt;Name&lt;/b&gt;');
  });

  test('escapes HTML in value', () => {
    const html = rvRow('Name', '<script>alert(1)</script>');
    expect(html).toContain('&lt;script&gt;');
    expect(html).not.toContain('<script>');
  });

  test('wraps output in a div', () => {
    const html = rvRow('Phone', '555-1234');
    expect(html.startsWith('<div>')).toBe(true);
    expect(html.endsWith('</div>')).toBe(true);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// rvVal  (DOM-dependent — uses jsdom from Jest)
// ─────────────────────────────────────────────────────────────────────────────
describe('rvVal()', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  test('returns trimmed value of an existing input', () => {
    document.body.innerHTML = '<input id="email" value="  hello@example.com  ">';
    expect(rvVal('email')).toBe('hello@example.com');
  });

  test('returns empty string when element does not exist', () => {
    expect(rvVal('nonexistent')).toBe('');
  });

  test('returns empty string when input is empty', () => {
    document.body.innerHTML = '<input id="phone" value="">';
    expect(rvVal('phone')).toBe('');
  });

  test('reads select value', () => {
    document.body.innerHTML = `
      <select id="hear_from_us_select">
        <option value="Google search" selected>Google search</option>
      </select>`;
    expect(rvVal('hear_from_us_select')).toBe('Google search');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// kidReviewCard
// ─────────────────────────────────────────────────────────────────────────────
describe('kidReviewCard()', () => {
  const fullKid = {
    first_name: 'Alice',
    last_name:  'Smith',
    date_of_birth: '2018-05-10',
    age:        '6',
    gender:     'Girl',
    last_grade: 'Kindergarten',
    t_shirt:    'YS',
    medical:    'Peanut allergy',
  };

  test('includes child number (1-based)', () => {
    expect(kidReviewCard(fullKid, 0)).toContain('Child 1:');
    expect(kidReviewCard(fullKid, 2)).toContain('Child 3:');
  });

  test('includes full name', () => {
    const html = kidReviewCard(fullKid, 0);
    expect(html).toContain('Alice Smith');
  });

  test('escapes special characters in name', () => {
    const html = kidReviewCard({ first_name: '<Bob>', last_name: '&son' }, 0);
    expect(html).toContain('&lt;Bob&gt;');
    expect(html).toContain('&amp;son');
    expect(html).not.toContain('<Bob>');
  });

  test('includes DOB when provided', () => {
    const html = kidReviewCard(fullKid, 0);
    expect(html).toContain('2018-05-10');
  });

  test('includes age when provided', () => {
    expect(kidReviewCard(fullKid, 0)).toContain('>6<');
  });

  test('includes gender when provided', () => {
    expect(kidReviewCard(fullKid, 0)).toContain('Girl');
  });

  test('includes grade when provided', () => {
    expect(kidReviewCard(fullKid, 0)).toContain('Kindergarten');
  });

  test('includes t-shirt size when provided', () => {
    expect(kidReviewCard(fullKid, 0)).toContain('YS');
  });

  test('includes medical info when provided', () => {
    expect(kidReviewCard(fullKid, 0)).toContain('Peanut allergy');
  });

  test('omits empty optional fields silently', () => {
    const html = kidReviewCard({ first_name: 'Tom', last_name: 'Lee' }, 0);
    expect(html).not.toContain('Birthday');
    expect(html).not.toContain('Allergies');
  });

  test('wraps output in a border div', () => {
    const html = kidReviewCard(fullKid, 0);
    expect(html).toContain('border');
    expect(html).toContain('rounded-lg');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// populateReview() — integration test via DOM
// ─────────────────────────────────────────────────────────────────────────────
describe('populateReview() DOM integration', () => {
  // Set up the minimal DOM required by populateReview()
  beforeEach(() => {
    // Expose helpers as a browser global (mirrors the <script> tag)
    global.window = global.window || {};
    global.window.ReviewHelpers = { escHtml, rvRow, rvVal, kidReviewCard };

    document.body.innerHTML = `
      <!-- Parent fields -->
      <input id="parent_first_name" value="Jane">
      <input id="parent_last_name"  value="Doe">
      <input id="email"             value="jane@example.com">
      <input id="phone"             value="408-555-0100">
      <input id="address"           value="123 Main St">
      <input id="home_church"       value="Crosspoint">
      <input id="alternative_pickup_name"  value="Grandma">
      <input id="alternative_pickup_phone" value="408-555-0199">
      <input id="emergency_contact_name"         value="Bob Doe">
      <input id="emergency_contact_phone"        value="408-555-0111">
      <input id="emergency_contact_relationship" value="Spouse">
      <select id="hear_from_us_select">
        <option value="Friend or family referral" selected>Friend or family referral</option>
        <option value="Other">Other</option>
      </select>
      <input id="hear_from_us_other" value="">

      <!-- Review output targets -->
      <dl id="rv-parent"></dl>
      <div id="rv-kids"></div>

      <!-- Kids container with one kid -->
      <div id="kids-container">
        <div class="kid-block">
          <input name="kids[0][first_name]" value="Alice">
          <input name="kids[0][last_name]"  value="Doe">
          <input name="kids[0][date_of_birth]" type="date" value="2018-05-10">
          <input name="kids[0][age]"  type="number" value="6">
          <select name="kids[0][gender]"><option value="Girl" selected>Girl</option></select>
          <select name="kids[0][last_grade_completed]"><option value="Kindergarten" selected>Kindergarten</option></select>
          <select name="kids[0][t_shirt_size]"><option value="YS" selected>YS</option></select>
          <textarea name="kids[0][medical_allergy_info]">Peanut allergy</textarea>
        </div>
      </div>
    `;

    // Define populateReview in the test scope (it's an IIFE in register.php —
    // we inline its body here so we can call it directly in tests)
    global.populateReview = function () {
      var escHtml       = window.ReviewHelpers.escHtml;
      var rvRow         = window.ReviewHelpers.rvRow;
      var rvVal         = window.ReviewHelpers.rvVal;
      var kidReviewCard = window.ReviewHelpers.kidReviewCard;

      var hearVal = rvVal('hear_from_us_select');
      if (hearVal === 'Other') {
        var other = rvVal('hear_from_us_other');
        hearVal = other ? 'Other: ' + other : 'Other';
      }

      var parentEl = document.getElementById('rv-parent');
      if (parentEl) {
        parentEl.innerHTML =
          rvRow('Name',    rvVal('parent_first_name') + ' ' + rvVal('parent_last_name')) +
          rvRow('Email',   rvVal('email')) +
          rvRow('Phone',   rvVal('phone')) +
          rvRow('Address', rvVal('address')) +
          rvRow('Church',  rvVal('home_church')) +
          rvRow('Alt. pick-up',
            rvVal('alternative_pickup_name') +
            (rvVal('alternative_pickup_phone') ? ' · ' + rvVal('alternative_pickup_phone') : '')) +
          rvRow('Emergency contact',
            rvVal('emergency_contact_name') + ' · ' +
            rvVal('emergency_contact_phone') + ' (' +
            rvVal('emergency_contact_relationship') + ')') +
          rvRow('Heard about us', hearVal);
      }

      var kidsEl = document.getElementById('rv-kids');
      if (kidsEl) {
        var kidHtml = '';
        document.querySelectorAll('#kids-container .kid-block').forEach(function (block, i) {
          var g = function (sel) { var e = block.querySelector(sel); return e ? e.value.trim() : ''; };
          kidHtml += kidReviewCard({
            first_name:    g('[name*="[first_name]"]'),
            last_name:     g('[name*="[last_name]"]'),
            date_of_birth: g('[name*="[date_of_birth]"]'),
            age:           g('[name*="[age]"]'),
            gender:        g('[name*="[gender]"]'),
            last_grade:    g('[name*="[last_grade_completed]"]'),
            t_shirt:       g('[name*="[t_shirt_size]"]'),
            medical:       g('[name*="[medical_allergy_info]"]'),
          }, i);
        });
        kidsEl.innerHTML = kidHtml || '<p class="text-gray-400 italic">No children added.</p>';
      }
    };
  });

  test('populates parent name into rv-parent', () => {
    populateReview();
    expect(document.getElementById('rv-parent').innerHTML).toContain('Jane Doe');
  });

  test('populates email into rv-parent', () => {
    populateReview();
    expect(document.getElementById('rv-parent').innerHTML).toContain('jane@example.com');
  });

  test('populates phone into rv-parent', () => {
    populateReview();
    expect(document.getElementById('rv-parent').innerHTML).toContain('408-555-0100');
  });

  test('populates emergency contact into rv-parent', () => {
    populateReview();
    expect(document.getElementById('rv-parent').innerHTML).toContain('Bob Doe');
    expect(document.getElementById('rv-parent').innerHTML).toContain('Spouse');
  });

  test('populates "how did you hear" from select', () => {
    populateReview();
    expect(document.getElementById('rv-parent').innerHTML).toContain('Friend or family referral');
  });

  test('displays "Other: ..." when hear_from_us is Other with text', () => {
    document.getElementById('hear_from_us_select').value = 'Other';
    document.getElementById('hear_from_us_other').value  = 'Billboard';
    populateReview();
    expect(document.getElementById('rv-parent').innerHTML).toContain('Other: Billboard');
  });

  test('populates first kid name into rv-kids', () => {
    populateReview();
    expect(document.getElementById('rv-kids').innerHTML).toContain('Alice Doe');
  });

  test('populates kid DOB into rv-kids', () => {
    populateReview();
    expect(document.getElementById('rv-kids').innerHTML).toContain('2018-05-10');
  });

  test('populates kid medical info into rv-kids', () => {
    populateReview();
    expect(document.getElementById('rv-kids').innerHTML).toContain('Peanut allergy');
  });

  test('shows "No children added" when container is empty', () => {
    document.getElementById('kids-container').innerHTML = '';
    populateReview();
    expect(document.getElementById('rv-kids').innerHTML).toContain('No children added');
  });

  test('re-populates on second call (reflects updated form values)', () => {
    populateReview();
    document.getElementById('parent_first_name').value = 'Updated';
    populateReview();
    expect(document.getElementById('rv-parent').innerHTML).toContain('Updated Doe');
  });
});
