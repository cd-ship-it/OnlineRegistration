/**
 * review-helpers.js
 * Pure utility functions used by the Step-3 Review panel in register.php.
 * Exported via CommonJS when running in Node (Jest tests), attached to
 * window.ReviewHelpers when running in a browser.
 */

'use strict';

/**
 * Escape a string for safe HTML insertion.
 * @param {string} str
 * @returns {string}
 */
function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/**
 * Build a single <dt>/<dd> pair for a review row.
 * Returns an empty string when value is blank so the layout stays tidy.
 * @param {string} label
 * @param {string} value
 * @returns {string} HTML fragment
 */
function rvRow(label, value) {
  if (!value || !value.trim()) return '';
  return '<div>'
    + '<dt class="font-medium text-gray-500">' + escHtml(label) + '</dt>'
    + '<dd class="mt-0.5">' + escHtml(value) + '</dd>'
    + '</div>';
}

/**
 * Return the trimmed value of a form element by id, or '' if not found.
 * @param {string} id  Element id
 * @returns {string}
 */
function rvVal(id) {
  if (typeof document === 'undefined') return '';
  var el = document.getElementById(id);
  return el ? el.value.trim() : '';
}

/**
 * Build the HTML for a single kid's review card.
 * @param {Object} kid  Plain object with kid field values
 * @param {number} index  0-based index
 * @returns {string} HTML fragment
 */
function kidReviewCard(kid, index) {
  var rows = [
    kid.date_of_birth ? '<div><dt class="text-gray-500 text-xs">Birthday</dt><dd>' + escHtml(kid.date_of_birth) + '</dd></div>' : '',
    kid.age            ? '<div><dt class="text-gray-500 text-xs">Age</dt><dd>' + escHtml(String(kid.age)) + '</dd></div>' : '',
    kid.gender         ? '<div><dt class="text-gray-500 text-xs">Gender</dt><dd>' + escHtml(kid.gender) + '</dd></div>' : '',
    kid.last_grade     ? '<div><dt class="text-gray-500 text-xs">Grade entering</dt><dd>' + escHtml(kid.last_grade) + '</dd></div>' : '',
    kid.t_shirt        ? '<div><dt class="text-gray-500 text-xs">T-shirt</dt><dd>' + escHtml(kid.t_shirt) + '</dd></div>' : '',
    kid.medical        ? '<div class="col-span-2 sm:col-span-3"><dt class="text-gray-500 text-xs">Allergies / medical</dt><dd>' + escHtml(kid.medical) + '</dd></div>' : '',
  ].join('');

  return '<div class="border border-gray-100 rounded-lg p-3">'
    + '<p class="font-semibold text-gray-800 mb-1">Child ' + (index + 1) + ': '
    + escHtml((kid.first_name || '') + ' ' + (kid.last_name || '')) + '</p>'
    + '<dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1">'
    + rows
    + '</dl>'
    + '</div>';
}

// CommonJS export (Node / Jest)
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { escHtml, rvRow, rvVal, kidReviewCard };
}

// Browser global
if (typeof window !== 'undefined') {
  window.ReviewHelpers = { escHtml, rvRow, rvVal, kidReviewCard };
}
