import React from 'react';
import { Link, useLocation } from 'react-router-dom';

type LegalDocument = {
  title: string;
  intro: string;
  sections: Array<{ heading: string; paragraphs: string[] }>;
};

const documents: Record<string, LegalDocument> = {
  '/privacy-policy': {
    title: 'Privacy Policy',
    intro: 'This policy explains how SolarNet uses information in its billing, customer-support, and Facebook Page Messenger services.',
    sections: [
      { heading: 'Information we process', paragraphs: ['SolarNet processes customer account, contact, billing, payment-reference, installation, support-ticket, and network-service information needed to provide and support internet service.', 'When someone messages the official SolarNet Facebook Page, SolarNet may store the Page-scoped sender identifier, message text, attachment metadata, timestamps, staff replies, and delivery results. SolarNet does not access personal Facebook conversations that were not sent to the SolarNet Page.'] },
      { heading: 'How information is used', paragraphs: ['Information is used to operate customer accounts, issue invoices and reminders, record payments, provide technical and billing support, protect the service, and maintain required operational records.', 'Messenger information is used to answer the sender, manage human handoff, prevent duplicate replies, and document support activity. Account-specific or sensitive billing information is directed to the secure customer portal.'] },
      { heading: 'Sharing and retention', paragraphs: ['SolarNet shares information only with service providers needed to operate hosting, email, SMS, payments, support, and Facebook Page messaging, or when legally required. SolarNet does not sell Messenger conversations or customer account information.', 'Records are retained only for operational, security, accounting, dispute-resolution, and legal requirements, then deleted or anonymized when no longer required.'] },
      { heading: 'Security and choices', paragraphs: ['SolarNet uses role-based staff access, encrypted connections, protected server credentials, and audit records. No internet service can be guaranteed completely secure.', 'Customers may request access, correction, or deletion where applicable by contacting solarnet.connection@gmail.com. Some billing, payment, security, or legal records may need to be retained.'] },
    ],
  },
  '/terms': {
    title: 'Terms of Service',
    intro: 'These terms apply to the SolarNet web portals and official Facebook Page support tools.',
    sections: [
      { heading: 'Authorized use', paragraphs: ['Customers may use the customer portal for their own SolarNet account. Staff tools are limited to authorized SolarNet employees and are protected by roles and permissions.', 'Users must not attempt unauthorized access, interfere with the service, upload malicious content, impersonate another person, or misuse payment and support features.'] },
      { heading: 'Billing and support information', paragraphs: ['Portal information reflects SolarNet operational records. Customers should contact authorized support if an invoice, payment, service status, or account detail appears incorrect.', 'Messenger replies provide general assistance and are not a substitute for secure account verification. SolarNet will not request passwords through Messenger.'] },
      { heading: 'Availability and changes', paragraphs: ['SolarNet works to keep its portals and support channels available but cannot guarantee uninterrupted access. Maintenance, network failures, third-party platforms, or security events may affect availability.', 'SolarNet may update these terms when its services or legal obligations change. Continued use after an update is subject to the revised terms.'] },
      { heading: 'Contact', paragraphs: ['Questions about these terms may be sent to solarnet.connection@gmail.com.'] },
    ],
  },
  '/data-deletion': {
    title: 'User Data Deletion',
    intro: 'You may request deletion of information received through the SolarNet Facebook Page integration.',
    sections: [
      { heading: 'How to request deletion', paragraphs: ['Email solarnet.connection@gmail.com with the subject “Facebook Data Deletion Request.” Identify the Facebook Page conversation and provide enough non-sensitive information for SolarNet to locate the request. Do not email passwords, payment card details, or other secrets.', 'SolarNet may reply through the same Messenger conversation or email to verify that the requester controls the relevant account before deleting data.'] },
      { heading: 'What will be deleted', paragraphs: ['After verification, SolarNet will delete or anonymize eligible Page-scoped identifiers, Messenger message content, attachment metadata, and automation records associated with the requester. SolarNet will confirm completion or explain any record that must be retained.'] },
      { heading: 'Records that may be retained', paragraphs: ['Billing, payment, fraud-prevention, security, legal, and dispute records may be retained when required by law or legitimate operational obligations. A Messenger deletion request does not automatically terminate internet service or delete a separately verified customer billing account.'] },
      { heading: 'Processing time', paragraphs: ['SolarNet will acknowledge a valid request and process it within a reasonable period, subject to identity verification and applicable legal requirements.'] },
    ],
  },
};

export default function LegalPage(): React.JSX.Element {
  const { pathname } = useLocation();
  const document = documents[pathname] ?? documents['/privacy-policy'];

  return (
    <main className="min-h-screen bg-slate-50 px-4 py-10 text-slate-900 dark:bg-slate-950 dark:text-slate-100 sm:px-6">
      <article className="mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-10">
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700 dark:text-sky-300">SolarNet Internet</p>
        <h1 className="mt-2 text-3xl font-bold">{document.title}</h1>
        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Effective September 2, 2026</p>
        <p className="mt-6 leading-7 text-slate-700 dark:text-slate-300">{document.intro}</p>
        <div className="mt-8 space-y-7">
          {document.sections.map((section) => (
            <section key={section.heading}>
              <h2 className="text-lg font-semibold">{section.heading}</h2>
              <div className="mt-2 space-y-3 text-sm leading-6 text-slate-700 dark:text-slate-300">
                {section.paragraphs.map((paragraph) => <p key={paragraph}>{paragraph}</p>)}
              </div>
            </section>
          ))}
        </div>
        <nav className="mt-10 flex flex-wrap gap-4 border-t border-slate-200 pt-5 text-sm dark:border-slate-800">
          <Link className="text-sky-700 hover:underline dark:text-sky-300" to="/privacy-policy">Privacy Policy</Link>
          <Link className="text-sky-700 hover:underline dark:text-sky-300" to="/terms">Terms</Link>
          <Link className="text-sky-700 hover:underline dark:text-sky-300" to="/data-deletion">Data Deletion</Link>
          <Link className="text-sky-700 hover:underline dark:text-sky-300" to="/login">Staff login</Link>
        </nav>
      </article>
    </main>
  );
}
