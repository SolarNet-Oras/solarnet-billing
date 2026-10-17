type DobInput = {
  publicKey: string;
  baseUrl?: string;
  paymentIntentId: string;
  clientKey: string;
  bankCode: 'bpi' | 'ubp';
  returnUrl: string;
};

export async function openPaymongoDob(input: DobInput): Promise<string> {
  const baseUrl = (input.baseUrl || 'https://api.paymongo.com/v1').replace(/\/$/, '');
  const authorization = `Basic ${btoa(`${input.publicKey}:`)}`;
  const methodResponse = await fetch(`${baseUrl}/payment_methods`, {
    method: 'POST',
    headers: { Authorization: authorization, 'Content-Type': 'application/json' },
    body: JSON.stringify({ data: { attributes: { type: 'dob', details: { bank_code: input.bankCode } } } }),
  });
  const methodPayload = await methodResponse.json();
  if (!methodResponse.ok) throw new Error(methodPayload?.errors?.[0]?.detail || 'PayMongo could not create the bank payment method.');
  const paymentMethod = methodPayload?.data?.id;
  if (!paymentMethod) throw new Error('PayMongo did not return a bank payment method.');

  const attachResponse = await fetch(`${baseUrl}/payment_intents/${encodeURIComponent(input.paymentIntentId)}/attach`, {
    method: 'POST',
    headers: { Authorization: authorization, 'Content-Type': 'application/json' },
    body: JSON.stringify({ data: { attributes: { payment_method: paymentMethod, client_key: input.clientKey, return_url: input.returnUrl } } }),
  });
  const attachPayload = await attachResponse.json();
  if (!attachResponse.ok) throw new Error(attachPayload?.errors?.[0]?.detail || 'PayMongo could not connect to the selected bank.');
  const redirectUrl = attachPayload?.data?.attributes?.next_action?.redirect?.url;
  if (!redirectUrl) throw new Error('PayMongo did not return the bank authorization page.');
  return redirectUrl;
}
