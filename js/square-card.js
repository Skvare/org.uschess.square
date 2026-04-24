(async function() {
  if (typeof window.squareApplicationId === 'undefined') return;

  const payments = Square.payments(window.squareApplicationId, window.squareLocationId);

  const card = await payments.card();
  await card.attach('#square-card-container');

  const form = document.querySelector('form#Main');
  if (!form) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    const result = await card.tokenize();
    if (result.status !== 'OK') {
      alert("Card could not be tokenized.");
      return;
    }
    // Exchange token → Civi token
    const response = await fetch(CRM.url('civicrm/square/token'), {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        token: result.token,
        processor_id: window.squareProcessorId,
        contact_id: window.squareContactId,
      }),
    });
    const json = await response.json();
    if (!json.success) {
      alert("Could not store card: " + json.error);
      return;
    }

    // Store Civi token in hidden field
    document.querySelector('#square_payment_token').value = json.civi_token;
    // Explicitly submit once everything is ready
    form.submit();
  });
})();