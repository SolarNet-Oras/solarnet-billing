export type PaymongoQrAttachResult = {
  payment_method_id: string;
  qr_image_url: string;
};

/**
 * PayMongo's documented browser-side QR Ph step. The public key is safe for
 * this request; the secret key remains exclusively in Laravel.
 */
export async function attachPaymongoQrPh(input: {
  publicKey: string;
  baseUrl?: string;
  paymentIntentId: string;
  clientKey: string;
}): Promise<PaymongoQrAttachResult> {
  const baseUrl = (input.baseUrl || 'https://api.paymongo.com/v1').replace(/\/$/, '');
  const authorization = `Basic ${window.btoa(`${input.publicKey}:`)}`;
  const paymentMethodResponse = await fetch(`${baseUrl}/payment_methods`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: authorization },
    body: JSON.stringify({ data: { attributes: { type: 'qrph' } } }),
  });
  const paymentMethodPayload = await paymentMethodResponse.json();
  if (!paymentMethodResponse.ok) throw new Error(paymentMethodPayload?.errors?.[0]?.detail || 'PayMongo could not create a QR Ph payment method.');
  const paymentMethodId = paymentMethodPayload?.data?.id;
  if (!paymentMethodId) throw new Error('PayMongo did not return a QR Ph payment method.');

  const attachResponse = await fetch(`${baseUrl}/payment_intents/${encodeURIComponent(input.paymentIntentId)}/attach`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: authorization },
    body: JSON.stringify({ data: { attributes: { payment_method: paymentMethodId, client_key: input.clientKey } } }),
  });
  const attachPayload = await attachResponse.json();
  if (!attachResponse.ok) throw new Error(attachPayload?.errors?.[0]?.detail || 'PayMongo could not attach the QR Ph payment method.');
  const imageUrl = attachPayload?.data?.attributes?.next_action?.code?.image_url;
  if (!imageUrl || !String(imageUrl).startsWith('data:image/')) throw new Error('PayMongo did not return the dynamic QR Ph image.');
  return { payment_method_id: paymentMethodId, qr_image_url: imageUrl };
}
