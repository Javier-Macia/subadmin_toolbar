/**
 * @file
 * Flags the page while the Material Symbols font is loading.
 *
 * The icons are ligatures, so until the font arrives they show as raw text.
 * The pending class lets the stylesheet hold them back, and it is removed
 * when the font is ready, fails, or takes too long.
 */
(function (doc) {
  'use strict';

  var root = doc.documentElement;
  var fonts = doc.fonts;
  var PENDING = 'subadmin-icons-pending';
  var TIMEOUT = 10000;

  if (!fonts || typeof fonts.load !== 'function') {
    return;
  }

  var done = function () {
    root.classList.remove(PENDING);
  };

  root.classList.add(PENDING);
  setTimeout(done, TIMEOUT);
  fonts.load('24px "Material Symbols Outlined"', 'home').then(done, done);
}(document));
