import React, { useState, useEffect } from 'react';
import { 
  FileText, 
  Plus, 
  Search, 
  Filter, 
  Download, 
  Loader2,
  PhilippinePeso, 
  Send,
  Eye,
  Clock,
  CheckCircle,
  AlertCircle,
  XCircle,
  Calendar
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

  const labels = [...new Set(methods.map((payment) => paymentMethodLabels[payment.payment_method] ?? payment.payment_method))];
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
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
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
  });

  useEffect(() => {
    fetchInvoices();
    fetchCustomers();
  }, [currentPage, statusFilter]);

  const fetchInvoices = async () => {
    try {
      setLoading(true);
      const params: any = { page: currentPage, per_page: 10 };
      
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
      const raw = response as unknown as { meta?: { last_page?: number }; last_page?: number };
      setTotalPages(raw.meta?.last_page ?? raw.last_page ?? 1);
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

    try {
      await invoiceService.recordPayment(selectedInvoice.id, paymentData);
      setShowPaymentModal(false);
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
    });
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
            onChange={(e) => setStatusFilter(e.target.value)}
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
                <tr key={invoice.id} className="hover:bg-gray-50">
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
                        className="text-blue-600 hover:text-blue-900"
                        title="View Details"
                      >
                        <Eye className="w-4 h-4" />
                      </button>
                      <button
                        onClick={() => handleDownloadPdf(invoice)}
                        disabled={downloadingInvoiceId !== null}
                        className="text-green-600 hover:text-green-900 disabled:cursor-wait disabled:opacity-60"
                        title={downloadingInvoiceId === invoice.id ? 'Downloading PDF…' : 'Download PDF'}
                      >
                        {downloadingInvoiceId === invoice.id
                          ? <Loader2 className="w-4 h-4 animate-spin" />
                          : <Download className="w-4 h-4" />}
                      </button>
                      {invoice.status === 'draft' && (
                        <button
                          onClick={() => handleMarkAsSent(invoice.id)}
                          className="text-purple-600 hover:text-purple-900"
                          title="Mark as Sent"
                        >
                          <Send className="w-4 h-4" />
                        </button>
                      )}
                      {invoice.balance > 0 && invoice.status !== 'cancelled' && (
                        <button
                          onClick={() => {
                            setSelectedInvoice(invoice);
                            setPaymentData({ ...paymentData, amount: invoice.balance });
                            setShowPaymentModal(true);
                          }}
                          className="text-indigo-600 hover:text-indigo-900"
                          title="Record Payment"
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
      {totalPages > 1 && (
        <div className="mt-6 flex justify-center gap-2">
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
                    className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"
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
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-md">
            <div className="p-6">
              <h2 className="text-xl font-bold text-gray-900 mb-4">Record Payment</h2>
              <div className="mb-4 p-4 bg-blue-50 rounded-lg">
                <div className="text-sm text-gray-600">Invoice: {selectedInvoice.invoice_number}</div>
                <div className="text-sm text-gray-600">Customer: {selectedInvoice.customer?.full_name}</div>
                <div className="text-lg font-bold text-gray-900 mt-2">
                  Balance Due: {formatPHP(selectedInvoice.balance)}
                </div>
              </div>
              <form onSubmit={handleRecordPayment}>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                    <input
                      type="number"
                      step="0.01"
                      value={paymentData.amount}
                      onChange={(e) => setPaymentData({ ...paymentData, amount: parseFloat(e.target.value) })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                      max={selectedInvoice.balance}
                      min="0.01"
                      required
                    />
                  </div>

                  <div>
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
                  </div>

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

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Transaction ID (Optional)</label>
                    <input
                      type="text"
                      value={paymentData.transaction_id}
                      onChange={(e) => setPaymentData({ ...paymentData, transaction_id: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>

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

                <div className="mt-6 flex justify-end gap-3">
                  <button
                    type="button"
                    onClick={() => {
                      setShowPaymentModal(false);
                      resetPaymentForm();
                    }}
                    className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                  >
                    Record Payment
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
                  className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"
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
