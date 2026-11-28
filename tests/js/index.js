/**
 * Entry point for `node --test tests/js`.
 *
 * Since Node 22+ (and strictly in Node 26) the test runner treats positional
 * arguments as files or globs and no longer expands a directory by itself, so
 * the directory resolves to this file. Every suite still lives in its own
 * `*.test.mjs` file; this module only imports them.
 *
 * Two ways to run the suite:
 *   node --test tests/js
 *   node --test (with no arguments, discovering every test file)
 */

import './smart-checkout/scaleMath.test.mjs';
import './smart-checkout/drivers.test.mjs';
import './smart-checkout/webSerial.test.mjs';
import './smart-checkout/confidence.test.mjs';
import './smart-checkout/recognition.test.mjs';
import './smart-checkout/inference.test.mjs';
import './smart-checkout/workerPayload.test.mjs';
import './smart-checkout/learning.test.mjs';
import './smart-checkout/seedGallery.test.mjs';
import './smart-checkout/engineLearning.test.mjs';
