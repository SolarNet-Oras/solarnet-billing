import React, { useState, useEffect } from 'react';
import {
  Ticket as TicketIcon,
  Plus,
  Search,
  Filter,
  MessageSquare,
  User,
  Clock,
  CheckCircle,
  AlertCircle,
  XCircle,
  ClipboardCheck,
} from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';
import ticketService from '../services/ticketService';
import { customerService } from '../services/customerService';
import type { Ticket, Customer } from '../types/api';

interface ProfileChangeRequest {
  id: string;
  status: 'pending' | 'approved' | 'rejected';
  requested_full_name: string | null;
  requested_service_plan: { id: string; name: string; price: number } | null;
  customer: { account_number: string; full_name: string; email: string | null };
  created_at: string;
}

const TicketsPage: React.FC = () => {
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [profileChanges, setProfileChanges] = useState<ProfileChangeRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [priorityFilter, setPriorityFilter] = useState<string>('all');
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [showViewModal, setShowViewModal] = useState(false);
  const [selectedTicket, setSelectedTicket] = useState<Ticket | null>(null);

  const [formData, setFormData] = useState({
    customer_id: '',
    subject: '',
    description: '',
    priority: 'medium' as const,
    category: 'general' as const,
  });

  useEffect(() => {
    fetchTickets();
    fetchCustomers();
    fetchProfileChanges();
  }, [statusFilter, priorityFilter]);

  const fetchTickets = async () => {
    try {
      setLoading(true);
      const params: any = { per_page: 20 };
      
      if (statusFilter !== 'all') {
        params.status = statusFilter;
      }
      if (priorityFilter !== 'all') {
        params.priority = priorityFilter;
      }

      const response = await ticketService.getTickets(params);
      setTickets(response.data);
    } catch (error) {
      console.error('Error fetching tickets:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchProfileChanges = async () => {
    try {
      const response = await api.get('/customer-profile-change-requests');
      setProfileChanges(response.data?.data || []);
    } catch (error) {
      console.error('Error fetching client change requests:', error);
      setProfileChanges([]);
    }
  };

  const reviewProfileChange = async (change: ProfileChangeRequest, decision: 'approve' | 'reject') => {
    const reviewNotes = decision === 'reject' ? window.prompt('Optional reason for rejecting this request:') : null;
    if (decision === 'reject' && reviewNotes === null) return;
    if (!window.confirm(`${decision === 'approve' ? 'Approve and apply' : 'Reject'} the requested changes for ${change.customer.full_name}?`)) return;

    try {
      const response = await api.post(
        `/customer-profile-change-requests/${change.id}/${decision}`,
        decision === 'reject' ? { review_notes: reviewNotes || undefined } : {},
      );
      await fetchProfileChanges();
      window.alert(response.data?.message || 'Client change request reviewed.');
    } catch (error: any) {
      window.alert(error.response?.data?.message || 'Could not review this request.');
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

  const handleCreateTicket = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await ticketService.createTicket(formData);
      setShowCreateModal(false);
      fetchTickets();
      resetForm();
    } catch (error) {
      console.error('Error creating ticket:', error);
    }
  };

  const handleUpdateStatus = async (ticketId: string, status: string) => {
    try {
      await ticketService.updateStatus(ticketId, status);
      fetchTickets();
    } catch (error) {
      console.error('Error updating status:', error);
    }
  };

  const resetForm = () => {
    setFormData({
      customer_id: '',
      subject: '',
      description: '',
      priority: 'medium',
      category: 'general',
    });
  };

  const getStatusBadge = (status: string) => {
    const badges = {
      open: { bg: 'bg-blue-100', text: 'text-blue-800', icon: AlertCircle },
      in_progress: { bg: 'bg-yellow-100', text: 'text-yellow-800', icon: Clock },
      resolved: { bg: 'bg-green-100', text: 'text-green-800', icon: CheckCircle },
      closed: { bg: 'bg-gray-100', text: 'text-gray-600', icon: XCircle },
    };

    const badge = badges[status as keyof typeof badges] || badges.open;
    const Icon = badge.icon;

    return (
      <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium ${badge.bg} ${badge.text}`}>
        <Icon className="w-3 h-3" />
        {status.toUpperCase().replace('_', ' ')}
      </span>
    );
  };

  const getPriorityBadge = (priority: string) => {
    const badges = {
      low: { bg: 'bg-gray-100', text: 'text-gray-700' },
      medium: { bg: 'bg-blue-100', text: 'text-blue-700' },
      high: { bg: 'bg-orange-100', text: 'text-orange-700' },
      urgent: { bg: 'bg-red-100', text: 'text-red-700' },
    };

    const badge = badges[priority as keyof typeof badges] || badges.medium;

    return (
      <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${badge.bg} ${badge.text}`}>
        {priority.toUpperCase()}
      </span>
    );
  };

  const filteredTickets = tickets.filter((ticket) => {
    const searchLower = searchTerm.toLowerCase();
    return (
      ticket.ticket_number.toLowerCase().includes(searchLower) ||
      ticket.subject.toLowerCase().includes(searchLower) ||
      ticket.customer?.full_name?.toLowerCase().includes(searchLower)
    );
  });

  return (
    <DashboardLayout>
      <div className="p-6">
        {/* Header */}
        <div className="mb-6">
          <h1 className="text-2xl font-bold text-gray-900">Support Tickets</h1>
          <p className="text-sm text-gray-600 mt-1">Manage customer support requests</p>
        </div>

        {/* Actions Bar */}
        <div className="mb-6 flex flex-col sm:flex-row justify-between gap-4">
          <div className="flex gap-3">
            <div className="relative flex-1 sm:w-64">
              <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
              <input
                type="text"
                placeholder="Search tickets..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
              />
            </div>

            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
            >
              <option value="all">All Status</option>
              <option value="open">Open</option>
              <option value="in_progress">In Progress</option>
              <option value="resolved">Resolved</option>
              <option value="closed">Closed</option>
            </select>

            <select
              value={priorityFilter}
              onChange={(e) => setPriorityFilter(e.target.value)}
              className="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
            >
              <option value="all">All Priority</option>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
          </div>

          <button
            onClick={() => setShowCreateModal(true)}
            className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium transition-colors"
          >
            <Plus className="w-4 h-4" />
            New Ticket
          </button>
        </div>

        <section className="mb-6" data-testid="client-profile-change-requests">
          <div className="mb-3 flex items-center gap-2">
            <ClipboardCheck className="h-5 w-5 text-blue-600" />
            <div>
              <h2 className="font-semibold text-gray-900">Client Change Requests</h2>
              <p className="text-sm text-gray-600">Approve a requested name or service-plan change before it affects a customer record or MikroTik queue.</p>
            </div>
          </div>
          <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase tracking-wider text-gray-500">
                <tr><th className="px-4 py-3 text-left">Client</th><th className="px-4 py-3 text-left">Requested change</th><th className="px-4 py-3 text-left">Status</th><th className="px-4 py-3 text-left">Submitted</th><th className="px-4 py-3 text-right">Actions</th></tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {profileChanges.map((change) => (
                  <tr key={change.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3"><div className="font-medium text-gray-900">{change.customer.full_name}</div><div className="text-xs text-gray-500">{change.customer.account_number}</div></td>
                    <td className="px-4 py-3 text-gray-800"><div>{change.requested_full_name && <>Name: <strong>{change.requested_full_name}</strong></>}</div>{change.requested_service_plan && <div className="text-xs text-gray-500">Plan: {change.requested_service_plan.name} · ₱{Number(change.requested_service_plan.price).toLocaleString('en-PH', { minimumFractionDigits: 2 })}/mo</div>}</td>
                    <td className="px-4 py-3 capitalize text-gray-700">{change.status}</td>
                    <td className="px-4 py-3 text-xs text-gray-500">{new Date(change.created_at).toLocaleString()}</td>
                    <td className="px-4 py-3 text-right">{change.status === 'pending' ? <><button onClick={() => void reviewProfileChange(change, 'approve')} className="mr-3 text-emerald-700 hover:underline">Approve</button><button onClick={() => void reviewProfileChange(change, 'reject')} className="text-rose-600 hover:underline">Reject</button></> : '—'}</td>
                  </tr>
                ))}
                {profileChanges.length === 0 && <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-500">No client change requests found.</td></tr>}
              </tbody>
            </table>
          </div>
        </section>

        {/* Tickets Table */}
        <div className="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-200">
          <table className="w-full">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Ticket #
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Customer
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Subject
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Category
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Priority
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Status
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Created
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {loading ? (
                <tr>
                  <td colSpan={8} className="px-6 py-12 text-center text-gray-500">
                    Loading tickets...
                  </td>
                </tr>
              ) : filteredTickets.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-6 py-12 text-center text-gray-500">
                    No tickets found
                  </td>
                </tr>
              ) : (
                filteredTickets.map((ticket) => (
                  <tr key={ticket.id} className="hover:bg-gray-50 transition-colors">
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                      {ticket.ticket_number}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      {ticket.customer?.full_name || 'N/A'}
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-900 max-w-xs truncate">
                      {ticket.subject}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 capitalize">
                      {ticket.subject.startsWith('New Installation Application') ? 'installation application' : ticket.category.replace('_', ' ')}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      {getPriorityBadge(ticket.priority)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      {getStatusBadge(ticket.status)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {new Date(ticket.created_at).toLocaleDateString()}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm">
                      <button
                        onClick={() => {
                          setSelectedTicket(ticket);
                          setShowViewModal(true);
                        }}
                        className="text-blue-600 hover:text-blue-900 font-medium"
                      >
                        View
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Create Ticket Modal */}
        {showCreateModal && (
          <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
              <div className="p-6">
                <h2 className="text-xl font-bold text-gray-900 mb-4">Create New Ticket</h2>
                <form onSubmit={handleCreateTicket}>
                  <div className="space-y-4">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                      <select
                        value={formData.customer_id}
                        onChange={(e) => setFormData({ ...formData, customer_id: e.target.value })}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
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

                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                      <input
                        type="text"
                        value={formData.subject}
                        onChange={(e) => setFormData({ ...formData, subject: e.target.value })}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Brief description of the issue"
                        required
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
                      <textarea
                        value={formData.description}
                        onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                        rows={4}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Detailed description of the issue"
                        required
                      />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                        <select
                          value={formData.priority}
                          onChange={(e) => setFormData({ ...formData, priority: e.target.value as any })}
                          className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                          <option value="low">Low</option>
                          <option value="medium">Medium</option>
                          <option value="high">High</option>
                          <option value="urgent">Urgent</option>
                        </select>
                      </div>

                      <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Category</label>
                        <select
                          value={formData.category}
                          onChange={(e) => setFormData({ ...formData, category: e.target.value as any })}
                          className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                          <option value="general">General</option>
                          <option value="technical">Technical</option>
                          <option value="billing">Billing</option>
                          <option value="network_issue">Network Issue</option>
                        </select>
                      </div>
                    </div>
                  </div>

                  <div className="mt-6 flex justify-end gap-3">
                    <button
                      type="button"
                      onClick={() => {
                        setShowCreateModal(false);
                        resetForm();
                      }}
                      className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors"
                    >
                      Cancel
                    </button>
                    <button
                      type="submit"
                      className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                    >
                      Create Ticket
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        )}

        {/* View Ticket Modal */}
        {showViewModal && selectedTicket && (
          <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
              <div className="p-6">
                <div className="flex justify-between items-start mb-6">
                  <div>
                    <h2 className="text-2xl font-bold text-gray-900">{selectedTicket.ticket_number}</h2>
                    <p className="text-sm text-gray-600 mt-1">{selectedTicket.subject}</p>
                  </div>
                  <button
                    onClick={() => setShowViewModal(false)}
                    className="text-gray-400 hover:text-gray-600"
                  >
                    <XCircle className="w-6 h-6" />
                  </button>
                </div>

                <div className="space-y-4">
                  <div className="flex gap-4">
                    {getStatusBadge(selectedTicket.status)}
                    {getPriorityBadge(selectedTicket.priority)}
                  </div>

                  <div className="grid grid-cols-2 gap-4 p-4 bg-gray-50 rounded-lg">
                    <div>
                      <p className="text-sm text-gray-600">Customer</p>
                      <p className="font-medium">{selectedTicket.customer?.full_name}</p>
                    </div>
                    <div>
                      <p className="text-sm text-gray-600">Category</p>
                      <p className="font-medium capitalize">{selectedTicket.subject.startsWith('New Installation Application') ? 'Installation application' : selectedTicket.category.replace('_', ' ')}</p>
                    </div>
                    <div>
                      <p className="text-sm text-gray-600">Created</p>
                      <p className="font-medium">{new Date(selectedTicket.created_at).toLocaleString()}</p>
                    </div>
                    <div>
                      <p className="text-sm text-gray-600">Assigned To</p>
                      <p className="font-medium">{selectedTicket.assignedTo?.name || 'Unassigned'}</p>
                    </div>
                  </div>

                  <div>
                    <h3 className="font-medium text-gray-900 mb-2">Description</h3>
                    <p className="text-gray-700 whitespace-pre-wrap">{selectedTicket.description}</p>
                  </div>

                  {selectedTicket.status !== 'closed' && (
                    <div className="flex gap-2 pt-4 border-t">
                      <button
                        onClick={() => handleUpdateStatus(selectedTicket.id, 'in_progress')}
                        disabled={selectedTicket.status === 'in_progress'}
                        className="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 disabled:opacity-50 text-sm"
                      >
                        Mark In Progress
                      </button>
                      <button
                        onClick={() => handleUpdateStatus(selectedTicket.id, 'resolved')}
                        disabled={selectedTicket.status === 'resolved'}
                        className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 text-sm"
                      >
                        Mark Resolved
                      </button>
                      <button
                        onClick={() => handleUpdateStatus(selectedTicket.id, 'closed')}
                        className="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm"
                      >
                        Close Ticket
                      </button>
                    </div>
                  )}
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </DashboardLayout>
  );
};

export default TicketsPage;
