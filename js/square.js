(function ($, CRM) {
  var SDK_PROMISE = null;
  var INIT_RUNNING = false;
  var CAPTURE_HANDLER_BOUND = false;

  // Initialize as soon as DOM is ready.
  $(document).ready(function() {
    initSquarePayments(document);
  });

  // Webform CiviCRM injects the billing block via AJAX then triggers these.
  // Re-init when fragments are inserted.
  $(document).on('crmLoad.square crmFormLoad.square', function (e) {
    initSquarePayments(e.target || document);
  });

  function toNativePromise(maybeThenable) {
    if (!maybeThenable) {
      return Promise.resolve();
    }
    // Native Promise or thenable with .catch
    if (typeof maybeThenable.then === 'function' && typeof maybeThenable.catch === 'function') {
      return maybeThenable;
    }
    // jQuery Deferred / jqXHR (has .done/.fail) or older thenables
    if (typeof maybeThenable.done === 'function' || typeof maybeThenable.fail === 'function') {
      return new Promise(function (resolve, reject) {
        if (typeof maybeThenable.done === 'function') {
          maybeThenable.done(resolve);
        }
        if (typeof maybeThenable.fail === 'function') {
          maybeThenable.fail(reject);
        }
        // If it only has .then, try it too.
        if (typeof maybeThenable.then === 'function') {
          try {
            maybeThenable.then(resolve, reject);
          } catch (e) {
            reject(e);
          }
        }
      });
    }
    if (typeof maybeThenable.then === 'function') {
      return new Promise(function (resolve, reject) {
        try {
          maybeThenable.then(resolve, reject);
        } catch (e) {
          reject(e);
        }
      });
    }
    return Promise.resolve(maybeThenable);
  }

  function ensureSquareSdkLoaded(isSandbox) {
    // Square global is provided by the SDK.
    if (window.Square && window.Square.payments) {
      return Promise.resolve();
    }

    if (SDK_PROMISE) {
      return SDK_PROMISE;
    }

    var sdkUrl = isSandbox
      ? 'https://sandbox.web.squarecdn.com/v1/square.js'
      : 'https://web.squarecdn.com/v1/square.js';

    // Prefer CiviCRM loader if present.
    if (CRM && typeof CRM.loadScript === 'function') {
      SDK_PROMISE = toNativePromise(CRM.loadScript(sdkUrl));
      return SDK_PROMISE;
    }

    SDK_PROMISE = new Promise(function (resolve, reject) {
      // Avoid duplicate insertion if another script added it already.
      var existing = document.querySelector('script[src="' + sdkUrl + '"]');
      if (existing) {
        existing.addEventListener('load', function () { resolve(); });
        existing.addEventListener('error', function () { reject(new Error('Failed to load Square SDK')); });
        // If it already loaded, resolve immediately.
        if ((window.Square && window.Square.payments)) {
          resolve();
        }
        return;
      }

      var s = document.createElement('script');
      s.src = sdkUrl;
      s.async = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('Failed to load Square SDK')); };
      (document.head || document.documentElement).appendChild(s);
    });

    return SDK_PROMISE;
  }

  function initSquarePayments(root) {
    // Prevent rapid double-inits when crmLoad + crmFormLoad both fire.
    if (INIT_RUNNING) {
      return;
    }
    INIT_RUNNING = true;

    var $root = root ? $(root) : $(document);

    // Try to get config from window variables first (more reliable)
    var appId = window.squareApplicationId;
    var locationId = window.squareLocationId;
    var isSandbox = !!window.squareIsSandbox;
    
    // Fall back to CRM.vars if window variables not set
    if (!appId || !locationId) {
      if (!CRM || !CRM.vars || !CRM.vars.orgUschessSquare) {
        console.warn('Square.js: CRM.vars.orgUschessSquare not found');
        INIT_RUNNING = false;
        return;
      }
      var cfg = CRM.vars.orgUschessSquare;
      appId = cfg.applicationId;
      locationId = cfg.locationId;
      isSandbox = !!cfg.isSandbox;
    }
    
    if (!appId || !locationId) {
      console.error('Square config missing applicationId or locationId');
      INIT_RUNNING = false;
      return;
    }

    // Check if card container exists (within root context if possible).
    var $cardContainer = $root.find('#square-card-container');
    if (!$cardContainer.length) {
      $cardContainer = $('#square-card-container');
    }
    if (!$cardContainer.length) {
      console.warn('Square.js: Card container not found');
      INIT_RUNNING = false;
      return;
    }

    // Avoid re-binding if already initialized for this container.
    if ($cardContainer.data('square-initialized')) {
      INIT_RUNNING = false;
      return;
    }
    
    // Verify token field exists
    var $tokenField = $root.find('#square_payment_token');
    if (!$tokenField.length) {
      $tokenField = $('#square_payment_token');
    }
    if (!$tokenField.length) {
      // Webform fragments can omit the hidden input in some cases; create it inside the parent form.
      console.warn('Square.js: Token field #square_payment_token not found; will create one.');
    }

    // Bind to the nearest form containing the Square UI.
    var $form = $cardContainer.closest('form');
    if (!$form.length) {
      console.warn('Square.js: No parent form found; trying common selectors');
      $form = $('form#Main').length ? $('form#Main') :
        $('form.CRM_Contribute_Form_Contribution').length ? $('form.CRM_Contribute_Form_Contribution') :
          $('form.CRM_Event_Form_Registration').length ? $('form.CRM_Event_Form_Registration') :
            $('form').first();
    }
    if (!$form.length) {
      console.error('Square.js: No form found at all');
      INIT_RUNNING = false;
      return;
    }

    // Ensure token field exists inside the form and has a name so it posts.
    if (!$tokenField || !$tokenField.length || !$tokenField.closest('form').is($form)) {
      $tokenField = $form.find('#square_payment_token');
    }
    if (!$tokenField.length) {
      $tokenField = $('<input type="hidden" id="square_payment_token" name="square_payment_token" />');
      $form.append($tokenField);
    }

    var payments = null;
    var card = null;
    var tokenized = false;

    // Mark initialized now (prevents double-init); if SDK load fails we unmark.
    $cardContainer.data('square-initialized', true);

    function showInitError() {
      var $error = $('#square-card-errors');
      $error
        .text('Unable to load secure card entry. Please try again later or contact support.')
        .show();
    }

    function initSquare() {
      return toNativePromise(ensureSquareSdkLoaded(isSandbox)).then(function () {
        if (!window.Square || !window.Square.payments) {
          throw new Error('Square.payments API not available after SDK load.');
        }
        payments = window.Square.payments(appId, locationId);
        if (!payments) {
          throw new Error('Failed to initialize Square payments.');
        }
        return payments.card().then(function (c) {
          card = c;
          return card.attach('#square-card-container');
        });
      }).catch(function () {
        // Allow future retries if this was a transient failure.
        $cardContainer.data('square-initialized', false);
        showInitError();
      }).then(function () {
        INIT_RUNNING = false;
      }, function () {
        INIT_RUNNING = false;
      });
    }

    // Initialize card UI.
    initSquare();

    async function tokenizeAndSubmit(event, submitterEl) {
      
      // If already tokenized, allow normal submission
      if (tokenized) {
        return true;
      }

      // If card is not initialized, let normal submit happen for server-side validation
      if (!card) {
        return true;
      }

      event.preventDefault();
      event.stopImmediatePropagation();

      var $error = $('#square-card-errors');
      $error.hide().text('');

      try {
        var result = await card.tokenize();
        if (!result || result.status !== 'OK') {
          var message = 'Your card could not be processed. Please check your details.';
          if (result && result.errors && result.errors.length) {
            message = result.errors[0].message || message;
          }
          console.error('Square.js: Tokenization failed', message);
          $error.text(message).show();
          return false;
        }

        var nonce = result.token;
        if (!nonce) {
          $error.text('Missing card token from Square. Please try again.').show();
          return false;
        }

        // Put token into hidden field for CiviCRM to pick up.
        $tokenField.val(nonce);

        // Mark as tokenized so next submit goes through
        tokenized = true;

        // Re-submit the form in a way that preserves the clicked button.
        // Webform wizard relies on the submitter button name/value.
        var formEl = $form.get(0);
        if (formEl) {
          // Prevent our own handler from re-running pointlessly.
          $form.off('submit.square');

          // Modern browsers: preserves submitter values.
          if (typeof formEl.requestSubmit === 'function') {
            try {
              formEl.requestSubmit(submitterEl || undefined);
              return true;
            } catch (e) {
              // Fall through to legacy methods.
            }
          }

          // Legacy fallback: click the submitter if we have it.
          if (submitterEl && typeof submitterEl.click === 'function') {
            submitterEl.click();
            return true;
          }

          // Last resort (may drop submitter values).
          formEl.submit();
        }

      } catch (e) {
        $error
          .text('Unexpected error processing your card. Please try again.')
          .show();
        return false;
      }
    }

    // Intercept submit in CAPTURE phase so we run before other handlers.
    // This is important in Webform, where other submit handlers may run first.
    if (!CAPTURE_HANDLER_BOUND) {
      CAPTURE_HANDLER_BOUND = true;
      document.addEventListener('submit', async function (event) {
        try {
          var formEl = event.target;
          if (!formEl || formEl.tagName !== 'FORM') {
            return;
          }

          // Only act when the Square container is inside the submitting form.
          var container = formEl.querySelector('#square-card-container');
          if (!container) {
            return;
          }

          // Pull runtime state from the container's jQuery data store.
          var $container = $(container);
          var state = $container.data('squareState');
          if (!state) {
            return;
          }

          if (state.tokenized) {
            return;
          }

          // If card isn't ready, allow normal submission (server will error).
          // We don't block here because the user might still be on earlier wizard steps.
          if (!state.card) {
            return;
          }

          event.preventDefault();
          if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
          }
          event.stopPropagation();

          var submitter = event.submitter || document.activeElement || null;
          await state.tokenizeAndSubmit(event, submitter);
        } catch (e) {
          // If anything goes wrong, allow form submission to proceed.
        }
      }, true);
    }

    // Store state on container for the capture-phase handler.
    $cardContainer.data('squareState', {
      get card() { return card; },
      get tokenized() { return tokenized; },
      tokenizeAndSubmit: tokenizeAndSubmit,
      setTokenized: function (v) { tokenized = !!v; },
    });
  }
})(CRM.$, CRM);