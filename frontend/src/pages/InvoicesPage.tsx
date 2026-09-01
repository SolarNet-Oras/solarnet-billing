import React, { useState, useEffect, useMemo } from 'react';
import { 
  FileText, 
  Plus, 
  Search, 
  Download, 
  Loader2,
  PhilippinePeso, 
  Send,
  Eye,
  Clock,
  CheckCircle,
  AlertCircle,
  XCircle,
} from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import invoiceService from '../services/invoiceService';
import type { Invoice, Customer, Payment } from '../types/api';
import { customerService } from '../services/customerService';
import { formatPHP } from '../lib/currency';

const paymentMethodLabels: Record<Payment['payment_method'], string> = {
  cash: 'Cash',
  bank_transfer: 'Bank Transfer',
  mobile_money: 'GCash',
  credit_card: 'Credit Card',
  debit_card: 'Debit Card',
  other: 'Other',
};

function PaymentMethod({ methods }: { methods: Payment[] }): React.JSX.Element {
  if (methods.length === 0) return <span className="text-gray-400">Not paid</span>;

  const labels = [...new Set(methods.map((payment) => payment.paymongo_checkout ? 'QR Ph' : (paymentMethodLabels[payment.payment_method] ?? payment.payment_method)))];
  return <span className="font-medium">{labels.join(', ')}</span>;
}

const InvoicesPage: React.FC = () => {
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [showViewModal, setShowViewModal] = useState(false);
  const [showPaymentModal, setShowPaymentModal] = useState(false);
  const [isAdvancePayment, setIsAdvancePayment] = useState(false);
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(100);
  const [totalPages, setTotalPages] = useState(1);
  const [totalInvoices, setTotalInvoices] = useState(0);
  const [downloadingInvoiceId, setDownloadingInvoiceId] = useState<string | null>(null);

  // Form states
  const [formData, setFormData] = useState({
    customer_id: '',
    billing_period_start: '',
    billing_period_end: '',
    due_days: 15,
    discount: 0,
    notes: '',
    additional_items: [] as Array<{ description: string; quantity: number; unit_price: number }>,
  });

  const [paymentData, setPaymentData] = useState({
    amount: 0,
    payment_method: 'cash' as const,
    payment_date: new Date().toISOString().split('T')[0],
    transaction_id: '',
    reference: '',
    notes: '',
    covered_cycle_date: '',
  });
  const [cashCounts, setCashCounts] = useState<Record<number, number>>({});
  const [cashChangeToAdvance, setCashChangeToAdvance] = useState(false);
  const cashBreakdown = useMemo(() => [1000, 500, 200, 100, 50, 20, 10, 5, 1].map((denomination) => ({ denomination, count: Number(cashCounts[denomination] || 0), amount: denomination * Number(cashCounts[denomination] || 0) })), [cashCounts]);
  const cashCounted = useMemo(() => cashBreakdown.reduce((total, line) => total + line.amount, 0), [cashBreakdown]);
  const paymentAmount = Number(paymentData.amount || 0);
  const advanceAmountIsValid = !isAdvancePayment || paymentAmount > 0;
  const cashCoversPayment = Math.round(cashCounted * 100) >= Math.round(paymentAmount * 100);
  const cashChange = Math.max(0, Math.round((cashCounted - paymentAmount) * 100) / 100);
  const cashShortfall = Math.max(0, Math.round((paymentAmount - cashCounted) * 100) / 100);
  const supportsChangeAsAdvance = !isAdvancePayment && paymentData.payment_method === 'cash';
  const hasCashChange = supportsChangeAsAdvance && cashChange > 0;
  const advanceCreditFromChange = hasCashChange && cashChangeToAdvance ? cashChange : 0;
  const changeToReturn = Math.max(0, Math.round((cashChange - advanceCreditFromChange) * 100) / 100);

  useEffect(() => {
    fetchInvoices();
    fetchCustomers();
  }, [currentPage, statusFilter, perPage]);

  const fetchInvoices = async () => {
    try {
      setLoading(true);
      const params: any = { page: currentPage, per_page: perPage };
      
      if (statusFilter !== 'all') {
        if (statusFilter === 'unpaid') {
          params.unpaid = true;
        } else if (statusFilter === 'overdue') {
          params.overdue = true;
        } else {
          params.status = statusFilter;
        }
      }

      const response = await invoiceService.getInvoices(params);
      setInvoices(response.data);
      const raw = response as unknown as { meta?: { last_page?: number; total?: number }; last_page?: number; total?: number };
      setTotalPages(raw.meta?.last_page ?? raw.last_page ?? 1);
      setTotalInvoices(raw.meta?.total ?? raw.total ?? response.data.length);
    } catch (error) {
      console.error('Error fetching invoices:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchCustomers = async () => {
    try {
      const response = await customerService.getCustomers({ per_page: 1000 });
      setCustomers(response.data);
    } catch (error) {
      console.error('Error fetching customers:', error);
    }
  };

  const handleCreateInvoice = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await invoiceService.createInvoice(formData);
      setShowCreateModal(false);
      fetchInvoices();
      resetForm();
    } catch (error) {
      console.error('Error creating invoice:', error);
    }
  };

  const handleRecordPayment = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedInvoice) return;

    if (isAdvancePayment && !advanceAmountIsValid) {
      window.alert('Enter a cash amount greater than ₱0.00 to create advance credit. A ₱0.00 payment cannot be recorded as customer credit.');
      return;
    }
    if (paymentData.payment_method === 'cash' && !cashCoversPayment) {
      window.alert('Cash received must cover the payment amount. Enter the bills received, then return the displayed change to the client.');
      return;
    }
    try {
      const requestData = {
        ...paymentData,
        amount: paymentAmount,
        ...(paymentData.payment_method === 'cash' ? {
          cash_breakdown: cashBreakdown.map(({ denomination, count }) => ({ denomination, count })),
          cash_change_to_advance: hasCashChange && cashChangeToAdvance,
        } : {}),
      };
      if (isAdvancePayment) {
        await invoiceService.recordAdvancePayment({ ...requestData, customer_id: selectedInvoice.customer_id });
      } else {
        await invoiceService.recordPayment(selectedInvoice.id, requestData);
      }
      setShowPaymentModal(false);
      setIsAdvancePayment(false);
      fetchInvoices();
      resetPaymentForm();
    } catch (error) {
      console.error('Error recording payment:', error);
    }
  };

  const handleDownloadPdf = async (invoice: Invoice) => {
    setDownloadingInvoiceId(invoice.id);
    try {
      const blob = await invoiceService.downloadPdf(invoice.id);
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `invoice-${invoice.invoice_number}.pdf`;
      link.click();
      window.URL.revokeObjectURL(url);
    } catch (error) {
      console.error('Error downloading PDF:', error);
    } finally {
      setDownloadingInvoiceId(null);
    }
  };

  const handleMarkAsSent = async (invoiceId: string) => {
    try {
      await invoiceService.markAsSent(invoiceId);
      fetchInvoices();
    } catch (error) {
      console.error('Error marking as sent:', error);
    }
  };

  const resetForm = () => {
    setFormData({
      customer_id: '',
      billing_period_start: '',
      billing_period_end: '',
      due_days: 15,
      discount: 0,
      notes: '',
      additional_items: [],
    });
  };

  const resetPaymentForm = () => {
    setPaymentData({
      amount: 0,
      payment_method: 'cash',
      payment_date: new Date().toISOString().split('T')[0],
      transaction_id: '',
      reference: '',
      notes: '',
      covered_cycle_date: '',
    });
    setCashCounts({});
    setCashChangeToAdvance(false);
    setIsAdvancePayment(false);
  };

  const addAdditionalItem = () => {
    setFormData({
      ...formData,
      additional_items: [...formData.additional_items, { description: '', quantity: 1, unit_price: 0 }],
    });
  };

  const removeAdditionalItem = (index: number) => {
    setFormData({
      ...formData,
      additional_items: formData.additional_items.filter((_, i) => i !== index),
    });
  };

  const getStatusBadge = (status: string) => {
    const badges = {
      draft: { bg: 'bg-gray-100', text: 'text-gray-800', icon: FileText },
      sent: { bg: 'bg-blue-100', text: 'text-blue-800', icon: Send },
      partial: { bg: 'bg-yellow-100', text: 'text-yellow-800', icon: Clock },
      paid: { bg: 'bg-green-100', text: 'text-green-800', icon: CheckCircle },
      overdue: { bg: 'bg-red-100', text: 'text-red-800', icon: AlertCircle },
      cancelled: { bg: 'bg-gray-100', text: 'text-gray-600', icon: XCircle },
    };

    const badge = badges[status as keyof typeof badges] || badges.draft;
    const Icon = badge.icon;

    return (
      <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium ${badge.bg} ${badge.text}`}>
        <Icon className="w-3 h-3" />
        {status.toUpperCase()}
      </span>
    );
  };

  const filteredInvoices = invoices.filter((invoice) => {
    const searchLower = searchTerm.toLowerCase();
    return (
      invoice.invoice_number.toLowerCase().includes(searchLower) ||
      invoice.customer?.full_name?.toLowerCase().includes(searchLower) ||
      invoice.customer?.account_number?.toLowerCase().includes(searchLower)
    );
  });

  return (
    <DashboardLayout>
      <div className="rounded-2xl bg-white p-6 text-gray-900 shadow-sm">
      {/* Header */}
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Invoices</h1>
        <p className="text-sm text-gray-600 mt-1">Manage customer invoices and billing</p>
      </div>

      {/* Actions Bar */}
      <div className="mb-6 flex flex-col sm:flex-row justify-between gap-4">
        <div className="flex gap-3">
          <div className="relative flex-1 sm:w-64">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
            <input
              type="text"
              placeholder="Search invoices..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full bg-white pl-10 pr-4 py-2 text-gray-900 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
            />
          </div>

          <select
            value={statusFilter}
            onChange={(e) => { setStatusFilter(e.target.value); setCurrentPage(1); }}
            className="bg-white px-4 py-2 text-gray-900 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
          >
            <option value="all">All Status</option>
            <option value="draft">Draft</option>
            <option value="sent">Sent</option>
            <option value="partial">Partial</option>
            <option value="paid">Paid</option>
            <option value="overdue">Overdue</option>
            <option value="unpaid">Unpaid</option>
          </select>

          <label className="inline-flex items-center gap-2 whitespace-nowrap text-sm text-gray-600">
            Rows
            <select
              value={perPage}
              onChange={(event) => { setPerPage(Number(event.target.value)); setCurrentPage(1); }}
              className="bg-white px-3 py-2 text-gray-900 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
              aria-label="Invoices per page"
            >
              {[10, 25, 50, 100].map((size) => <option key={size} value={size}>{size}</option>)}
            </select>
          </label>
        </div>

        <button
          onClick={() => setShowCreateModal(true)}
          className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium transition-colors"
        >
          <Plus className="w-4 h-4" />
          Generate Invoice
        </button>
      </div>

      {/* Invoices Table */}
      <div className="bg-white rounded-lg shadow overflow-hidden">
        <table className="w-full">
          <thead className="bg-gray-50 border-b border-gray-200">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Invoice #
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Customer
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Issue Date
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Due Date
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Total
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Balance
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Payment Method
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Status
              </th>
              <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                Actions
              </th>
            </tr>
          </thead>
          <tbody className="bg-white divide-y divide-gray-200">
            {loading ? (
              <tr>
                <td colSpan={9} className="px-6 py-12 text-center text-gray-500">
                  Loading invoices...
                </td>
              </tr>
            ) : filteredInvoices.length === 0 ? (
              <tr>
                <td colSpan={9} className="px-6 py-12 text-center text-gray-500">
                  No invoices found
                </td>
              </tr>
            ) : (
              filteredInvoices.map((invoice) => (
                <tr key={invoice.id} className="transition-colors hover:bg-gray-50 dark:hover:bg-slate-800/90">
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    {invoice.invoice_number}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {invoice.customer?.full_name || 'N/A'}
                    <span className="block text-xs text-gray-500">{invoice.customer?.account_number}</span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {new Date(invoice.issue_date).toLocaleDateString()}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {new Date(invoice.due_date).toLocaleDateString()}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    {formatPHP(invoice.total)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    {formatPHP(invoice.balance)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                    <PaymentMethod methods={invoice.payments ?? []} />
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    {getStatusBadge(invoice.status)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <div className="flex justify-end gap-2">
                      <button
                        onClick={() => {
                          setSelectedInvoice(invoice);
                          setShowViewModal(true);
                        }}
                        className="text-blue-600 hover:text-blue-900 dark:text-cyan-400 dark:hover:text-cyan-200"
                        title="View Details"
                      >
                        <Eye className="w-4 h-4" />
                      </button>
                      <button
                        onClick={() => handleDownloadPdf(invoice)}
                        disabled={downloadingInvoiceId !== null}
                        className="text-green-600 hover:text-green-900 disabled:cursor-wait disabled:opacity-60 dark:text-emerald-400 dark:hover:text-emerald-200"
                        title={downloadingInvoiceId === invoice.id ? 'Downloading PDF…' : 'Download PDF'}
                      >
                        {downloadingInvoiceId === invoice.id
                          ? <Loader2 className="w-4 h-4 animate-spin" />
                          : <Download className="w-4 h-4" />}
                      </button>
                      {invoice.status === 'draft' && (
                        <button
                          onClick={() => handleMarkAsSent(invoice.id)}
                          className="text-purple-600 hover:text-purple-900 dark:text-violet-400 dark:hover:text-violet-200"
                          title="Mark as Sent"
                        >
                          <Send className="w-4 h-4" />
                        </button>
                      )}
                      {invoice.balance > 0 && invoice.status !== 'cancelled' && (
                        <button
                          onClick={() => {
                            setSelectedInvoice(invoice);
                            setCashChangeToAdvance(false);
                            setPaymentData({ ...paymentData, amount: invoice.balance });
                            setShowPaymentModal(true);
                          }}
                          className="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-200"
                          title="Record Payment"
                        >
                          <PhilippinePeso className="w-4 h-4" />
                        </button>
                      )}
                      {invoice.balance <= 0 && invoice.status !== 'cancelled' && (
                        <button
                          onClick={() => {
                            setSelectedInvoice(invoice);
                            setIsAdvancePayment(true);
                            setCashChangeToAdvance(false);
                            setPaymentData({ ...paymentData, amount: 0, payment_method: 'cash', transaction_id: '' });
                            setCashCounts({});
                            setShowPaymentModal(true);
                          }}
                          className="ml-2 text-emerald-600 hover:text-emerald-900"
                          title="Record advance payment for next month"
                        >
                          <PhilippinePeso className="w-4 h-4" />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      <div className="mt-6 flex flex-col items-center justify-between gap-3 sm:flex-row">
        <span className="text-sm text-gray-600">
          Showing {invoices.length === 0 ? 0 : ((currentPage - 1) * perPage) + 1}–{Math.min(currentPage * perPage, totalInvoices)} of {totalInvoices} invoices
        </span>
        {totalPages > 1 && (
        <div className="flex justify-center gap-2">
          <button
            onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
            disabled={currentPage === 1}
            className="px-4 py-2 border border-gray-300 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Previous
          </button>
          <span className="px-4 py-2 text-sm text-gray-700">
            Page {currentPage} of {totalPages}
          </span>
          <button
            onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
            disabled={currentPage === totalPages}
            className="px-4 py-2 border border-gray-300 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Next
          </button>
        </div>
        )}
      </div>

      {/* Create Invoice Modal */}
      {showCreateModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white text-gray-900 rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div className="p-6">
              <h2 className="text-xl font-bold text-gray-900 mb-4">Generate New Invoice</h2>
              <form onSubmit={handleCreateInvoice}>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                    <select
                      value={formData.customer_id}
                      onChange={(e) => setFormData({ ...formData, customer_id: e.target.value })}
                      className="w-full bg-white px-3 py-2 text-gray-900 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                      required
                    >
                      <option value="">Select a customer</option>
                      {customers.map((customer) => (
                        <option key={customer.id} value={customer.id}>
                          {customer.full_name} ({customer.account_number})
                        </option>
                      ))}
                    </select>
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Billing Period Start</label>
                      <input
                        type="date"
                        value={formData.billing_period_start}
                        onChange={(e) => setFormData({ ...formData, billing_period_start: e.target.value })}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        required
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Billing Period End</label>
                      <input
                        type="date"
                        value={formData.billing_period_end}
                        onChange={(e) => setFormData({ ...formData, billing_period_end: e.target.value })}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        required
                      />
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Due Days</label>
                      <input
                        type="number"
                        value={formData.due_days}
                        onChange={(e) => setFormData({ ...formData, due_days: parseInt(e.target.value) })}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        min="1"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Discount (₱)</label>
                      <input
                        type="number"
                        step="0.01"
                        value={formData.discount}
                        onChange={(e) => setFormData({ ...formData, discount: parseFloat(e.target.value) })}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        min="0"
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea
                      value={formData.notes}
                      onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                      rows={2}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>

                  {/* Additional Items */}
                  <div>
                    <div className="flex justify-between items-center mb-2">
                      <label className="block text-sm font-medium text-gray-700">Additional Items</label>
                      <button
                        type="button"
                        onClick={addAdditionalItem}
                        className="text-sm text-blue-600 hover:text-blue-700"
                      >
                        + Add Item
                      </button>
                    </div>
                    {formData.additional_items.map((item, index) => (
                      <div key={index} className="grid grid-cols-12 gap-2 mb-2">
                        <input
                          type="text"
                          placeholder="Description"
                          value={item.description}
                          onChange={(e) => {
                            const newItems = [...formData.additional_items];
                            newItems[index].description = e.target.value;
                            setFormData({ ...formData, additional_items: newItems });
                          }}
                          className="col-span-6 px-3 py-2 border border-gray-300 rounded-lg text-sm"
                        />
                        <input
                          type="number"
                          placeholder="Qty"
                          value={item.quantity}
                          onChange={(e) => {
                            const newItems = [...formData.additional_items];
                            newItems[index].quantity = parseInt(e.target.value) || 1;
                            setFormData({ ...formData, additional_items: newItems });
                          }}
                          className="col-span-2 px-3 py-2 border border-gray-300 rounded-lg text-sm"
                          min="1"
                        />
                        <input
                          type="number"
                          placeholder="Price"
                          step="0.01"
                          value={item.unit_price}
                          onChange={(e) => {
                            const newItems = [...formData.additional_items];
                            newItems[index].unit_price = parseFloat(e.target.value) || 0;
                            setFormData({ ...formData, additional_items: newItems });
                          }}
                          className="col-span-3 px-3 py-2 border border-gray-300 rounded-lg text-sm"
                        />
                        <button
                          type="button"
                          onClick={() => removeAdditionalItem(index)}
                          className="col-span-1 text-red-600 hover:text-red-700"
                        >
                          <XCircle className="w-5 h-5" />
                        </button>
                      </div>
                    ))}
                  </div>
                </div>

                <div className="mt-6 flex justify-end gap-3">
                  <button
                    type="button"
                    onClick={() => {
                      setShowCreateModal(false);
                      resetForm();
                    }}
                    className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                  >
                    Generate Invoice
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      )}

      {/* Record Payment Modal */}
      {showPaymentModal && selectedInvoice && (
        <div className="fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-50 p-3 sm:flex sm:items-center sm:justify-center sm:p-6">
          <div className="mx-auto w-full max-w-2xl overflow-hidden rounded-xl bg-white shadow-xl">
            <div className="max-h-[calc(100vh-1.5rem)] overflow-y-auto p-4 sm:max-h-[calc(100vh-3rem)] sm:p-6">
              <h2 className="text-xl font-bold text-gray-900 mb-4">{isAdvancePayment ? 'Record Advance Payment' : 'Record Payment'}</h2>
              <div className="mb-4 p-4 bg-blue-50 rounded-lg">
                <div className="text-sm text-gray-600">Invoice: {selectedInvoice.invoice_number}</div>
                <div className="text-sm text-gray-600">Customer: {selectedInvoice.customer?.full_name}</div>
                <div className="text-lg font-bold text-gray-900 mt-2">
                  {isAdvancePayment ? 'This payment will be saved as credit for the customer’s next invoice.' : `Balance Due: ${formatPHP(selectedInvoice.balance)}`}
                </div>
                {isAdvancePayment && (
                  <p className="mt-2 text-sm text-emerald-800">
                    Current balance is {formatPHP(selectedInvoice.balance)}. Enter any cash amount above ₱0.00; it will appear as advance credit and automatically reduce the next eligible billing cycle.
                  </p>
                )}
              </div>
              <form onSubmit={handleRecordPayment}>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">{isAdvancePayment ? 'Advance amount' : 'Amount'}</label>
                    <input
                      type="number"
                      step="0.01"
                      value={paymentData.amount}
                      onChange={(e) => setPaymentData({ ...paymentData, amount: parseFloat(e.target.value) })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                      max={isAdvancePayment ? undefined : selectedInvoice.balance}
                      min="0.01"
                      placeholder="0.00"
                      required
                    />
                    {isAdvancePayment && <p className="mt-1 text-xs text-gray-500">The invoice can be ₱0.00; the payment itself must be greater than ₱0.00 to create advance credit.</p>}
                  </div>

                  {!isAdvancePayment && <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                    <select
                      value={paymentData.payment_method}
                      onChange={(e) => setPaymentData({ ...paymentData, payment_method: e.target.value as any })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                      required
                    >
                      <option value="cash">Cash</option>
                      <option value="bank_transfer">Bank Transfer</option>
                      <option value="credit_card">Credit Card</option>
                      <option value="debit_card">Debit Card</option>
                      <option value="mobile_money">Mobile Money</option>
                      <option value="other">Other</option>
                    </select>
                  </div>}

                  {isAdvancePayment && <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-900">Payment method: Cash only · Transaction ID is generated automatically.</div>}

                  {isAdvancePayment && <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Future billing cycle due date</label>
                    <input type="date" value={paymentData.covered_cycle_date} onChange={(e) => setPaymentData({ ...paymentData, covered_cycle_date: e.target.value })} className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500" />
                    <p className="mt-1 text-xs text-gray-500">Leave blank to reserve this payment for the next billing anniversary. Larger payments reserve later cycles in order; partial credit reduces that future invoice.</p>
                  </div>}

                  {paymentData.payment_method === 'cash' && <section className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 sm:p-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h3 className="font-semibold text-emerald-950">Cash received and change</h3><p className="mt-1 text-xs leading-5 text-emerald-800">Count the bills given by the client. You can return excess cash or, with the client’s approval, save it as advance credit for a future bill.</p></div><div className={`self-start rounded-lg px-3 py-2 text-left text-sm sm:text-right ${cashCoversPayment ? 'bg-emerald-600 text-white' : 'bg-amber-100 text-amber-900'}`}><p className="text-xs">Cash received</p><b>{formatPHP(cashCounted)}</b></div></div>
                    <div className="mt-4 rounded-lg border border-emerald-200 bg-white/70 p-2 sm:p-3"><div className="grid grid-cols-[4.5rem_minmax(6rem,1fr)_minmax(5.5rem,1fr)] gap-x-2 gap-y-2 text-sm sm:grid-cols-[minmax(6rem,1fr)_minmax(8rem,1fr)_minmax(8rem,1fr)] sm:gap-x-3"><span className="font-semibold text-emerald-900">Pieces</span><span className="font-semibold text-emerald-900">Denomination</span><span className="text-right font-semibold text-emerald-900">Amount</span>{cashBreakdown.map((line) => <React.Fragment key={line.denomination}><input min="0" inputMode="numeric" type="number" value={cashCounts[line.denomination] || ''} onChange={(event) => setCashCounts((current) => ({ ...current, [line.denomination]: Math.max(0, Number(event.target.value) || 0) }))} className="min-w-0 rounded border border-emerald-200 bg-white px-2 py-2 text-base" /><span className="self-center font-medium">₱{line.denomination.toLocaleString('en-PH')}</span><span className="self-center text-right font-semibold">{formatPHP(line.amount)}</span></React.Fragment>)}</div></div>
                    <div className="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2"><div className="rounded-lg bg-white/75 p-3"><p className="text-xs text-emerald-800">Payment amount</p><b className="text-emerald-950">{formatPHP(paymentAmount)}</b></div><div className={`rounded-lg p-3 ${cashChange > 0 ? 'bg-amber-100 text-amber-950' : 'bg-white/75 text-emerald-950'}`}><p className="text-xs">Change to return</p><b>{formatPHP(changeToReturn)}</b></div></div>
                    {supportsChangeAsAdvance && <label className={`mt-4 flex items-start gap-3 rounded-lg border p-3 text-sm ${hasCashChange ? 'cursor-pointer border-emerald-300 bg-white/75 text-emerald-950' : 'cursor-not-allowed border-gray-200 bg-gray-50 text-gray-500'}`}><input type="checkbox" disabled={!hasCashChange} checked={hasCashChange && cashChangeToAdvance} onChange={(event) => setCashChangeToAdvance(event.target.checked)} className="mt-0.5 h-5 w-5 shrink-0 rounded border-emerald-400 text-emerald-600 focus:ring-emerald-500 disabled:cursor-not-allowed disabled:opacity-50" /><span><span className="block font-semibold">Save excess cash as advance credit</span><span className="mt-1 block text-xs leading-5">{hasCashChange ? `Client approved: save ${formatPHP(cashChange)} instead of returning change. SolarNet will create a separate advance-credit receipt for the next eligible bill.` : 'This option unlocks after cash received is higher than the payment amount.'}</span></span></label>}
                    {advanceCreditFromChange > 0 && <div className="mt-4 rounded-lg bg-sky-100 p-3 text-sm text-sky-950"><p className="text-xs">Advance credit from change</p><b>{formatPHP(advanceCreditFromChange)}</b></div>}
                    <p className={`mt-3 text-xs font-medium ${cashCoversPayment ? 'text-emerald-700' : 'text-amber-800'}`}>{cashCoversPayment ? (cashChange > 0 ? (advanceCreditFromChange > 0 ? `${formatPHP(advanceCreditFromChange)} will be saved as advance credit. No cash change will be returned.` : `Return ${formatPHP(changeToReturn)} change to the client, then record the payment.`) : 'Exact cash received. You may record this payment.') : `Need ${formatPHP(cashShortfall)} more cash to cover the payment.`}</p>
                  </section>}

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Payment Date</label>
                    <input
                      type="date"
                      value={paymentData.payment_date}
                      onChange={(e) => setPaymentData({ ...paymentData, payment_date: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                      required
                    />
                  </div>

                  {!isAdvancePayment && <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Transaction ID (Optional)</label>
                    <input
                      type="text"
                      value={paymentData.transaction_id}
                      onChange={(e) => setPaymentData({ ...paymentData, transaction_id: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>}

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Reference (Optional)</label>
                    <input
                      type="text"
                      value={paymentData.reference}
                      onChange={(e) => setPaymentData({ ...paymentData, reference: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Notes (Optional)</label>
                    <textarea
                      value={paymentData.notes}
                      onChange={(e) => setPaymentData({ ...paymentData, notes: e.target.value })}
                      rows={2}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>
                </div>

                <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                  <button
                    type="button"
                    onClick={() => {
                      setShowPaymentModal(false);
                      resetPaymentForm();
                    }}
                    className="w-full rounded-lg border border-gray-300 px-4 py-2 text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800 sm:w-auto"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={(paymentData.payment_method === 'cash' && !cashCoversPayment) || !advanceAmountIsValid}
                    className="w-full rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                  >
                    {isAdvancePayment ? 'Save advance credit' : 'Record Payment'}
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      )}

      {/* View Invoice Details Modal - Simplified */}
      {showViewModal && selectedInvoice && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div className="p-6">
              <div className="flex justify-between items-start mb-4">
                <div>
                  <h2 className="text-2xl font-bold text-gray-900">{selectedInvoice.invoice_number}</h2>
                  <p className="text-sm text-gray-600 mt-1">Invoice Details</p>
                </div>
                <button
                  onClick={() => setShowViewModal(false)}
                  className="text-gray-400 hover:text-gray-600"
                >
                  <XCircle className="w-6 h-6" />
                </button>
              </div>

              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="text-sm font-medium text-gray-600">Customer</label>
                    <p className="text-sm text-gray-900">{selectedInvoice.customer?.full_name}</p>
                  </div>
                  <div>
                    <label className="text-sm font-medium text-gray-600">Status</label>
                    <div className="mt-1">{getStatusBadge(selectedInvoice.status)}</div>
                  </div>
                  <div>
                    <label className="text-sm font-medium text-gray-600">Issue Date</label>
                    <p className="text-sm text-gray-900">{new Date(selectedInvoice.issue_date).toLocaleDateString()}</p>
                  </div>
                  <div>
                    <label className="text-sm font-medium text-gray-600">Due Date</label>
                    <p className="text-sm text-gray-900">{new Date(selectedInvoice.due_date).toLocaleDateString()}</p>
                  </div>
                </div>

                <div className="border-t pt-4">
                  <h3 className="font-medium text-gray-900 mb-2">Amount Summary <span className="text-xs font-normal text-gray-500">(VAT-inclusive)</span></h3>
                  <div className="space-y-2 text-sm">
                    <div className="flex justify-between">
                      <span className="text-gray-600">VATable Sale:</span>
                      <span className="text-gray-900">{formatPHP(selectedInvoice.subtotal)}</span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-gray-600">VAT (8%):</span>
                      <span className="text-gray-900">{formatPHP(selectedInvoice.tax)}</span>
                    </div>
                    {selectedInvoice.discount > 0 && (
                      <div className="flex justify-between">
                        <span className="text-gray-600">Discount:</span>
                        <span className="text-gray-900">-{formatPHP(selectedInvoice.discount)}</span>
                      </div>
                    )}
                    <div className="flex justify-between font-bold text-base border-t pt-2">
                      <span>Total:</span>
                      <span>{formatPHP(selectedInvoice.total)}</span>
                    </div>
                    {selectedInvoice.paid_amount > 0 && (
                      <>
                        <div className="flex justify-between">
                          <span className="text-gray-600">Paid:</span>
                          <span className="text-green-600">-{formatPHP(selectedInvoice.paid_amount)}</span>
                        </div>
                        <div className="flex justify-between font-bold text-base text-blue-600">
                          <span>Balance Due:</span>
                          <span>{formatPHP(selectedInvoice.balance)}</span>
                        </div>
                      </>
                    )}
                  </div>
                </div>

                {selectedInvoice.notes && (
                  <div className="border-t pt-4">
                    <label className="text-sm font-medium text-gray-600">Notes</label>
                    <p className="text-sm text-gray-900 mt-1">{selectedInvoice.notes}</p>
                  </div>
                )}
              </div>

              <div className="mt-6 flex justify-end gap-3">
                <button
                  onClick={() => handleDownloadPdf(selectedInvoice)}
                  disabled={downloadingInvoiceId !== null}
                  className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center gap-2 disabled:cursor-wait disabled:opacity-70"
                >
                  {downloadingInvoiceId === selectedInvoice.id
                    ? <Loader2 className="w-4 h-4 animate-spin" />
                    : <Download className="w-4 h-4" />}
                  {downloadingInvoiceId === selectedInvoice.id ? 'Downloading…' : 'Download PDF'}
                </button>
                <button
                  onClick={() => setShowViewModal(false)}
                  className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
    </DashboardLayout>
  );
};

export default InvoicesPage;
