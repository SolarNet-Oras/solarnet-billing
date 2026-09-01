import React, { useState, useEffect, useMemo } from 'react';
import {
  Plus,
  Search,
  Clock,
  CheckCircle,
  AlertCircle,
  XCircle,
  ClipboardCheck,
  Trash2,
} from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { useAuth } from '@/hooks/useAuth';
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

interface InstallationPlanOption {
  id: string;
  name: string;
  download_speed: number;
  upload_speed: number;
  price: number;
}

const emptyInstallationApplication = {
  full_name: '', email: '', contact_number: '', address: '', service_plan_id: '', notes: '',
  gps_coordinates: undefined as { latitude: number; longitude: number } | undefined,
  location_accuracy_meters: undefined as number | undefined,
};

const TicketsPage: React.FC = () => {
  const { user } = useAuth();
  const canApproveInstallations = ['admin', 'super_admin'].some((role) =>
    user?.role === role || user?.roles?.some((item) => typeof item === 'string' ? item === role : item.name === role),
  );
  const canDeleteTickets = user?.permissions?.includes('delete-tickets')
    || user?.role === 'super_admin'
    || user?.roles?.some((item) => typeof item === 'string' ? item === 'super_admin' : item.name === 'super_admin');
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [installationApprovals, setInstallationApprovals] = useState<Ticket[]>([]);
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [profileChanges, setProfileChanges] = useState<ProfileChangeRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [priorityFilter, setPriorityFilter] = useState<string>('all');
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [customerSearch, setCustomerSearch] = useState('');
  const [showViewModal, setShowViewModal] = useState(false);
  const [selectedTicket, setSelectedTicket] = useState<Ticket | null>(null);
  const [showMacCorrection, setShowMacCorrection] = useState(false);
  const [macCorrection, setMacCorrection] = useState({ mac_address: '', reason: '' });
  const [installationPlans, setInstallationPlans] = useState<InstallationPlanOption[]>([]);
  const [installationForm, setInstallationForm] = useState(emptyInstallationApplication);
  const [installationLocating, setInstallationLocating] = useState(false);

  const [formData, setFormData] = useState({
    customer_id: '',
    ticket_type: 'other' as 'repair' | 'installation' | 'other',
    subject: '',
    description: '',
    priority: 'medium' as const,
    category: 'general' as const,
  });

  // The selected filters are the intended refresh triggers for these API loaders.
  /* oxlint-disable react-hooks/exhaustive-deps */
  useEffect(() => {
    fetchTickets();
    fetchInstallationApprovals();
    fetchCustomers();
    fetchProfileChanges();
    api.get('/customer-portal/service-plans')
      .then((response) => setInstallationPlans(response.data?.data || []))
      .catch(() => setInstallationPlans([]));
  }, [statusFilter, priorityFilter]);
  /* oxlint-enable react-hooks/exhaustive-deps */

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

  const fetchInstallationApprovals = async () => {
    try {
      const response = await ticketService.getTickets({ status: 'waiting_admin_approval', ticket_type: 'installation', per_page: 100 });
      setInstallationApprovals(response.data);
    } catch (error) {
      console.error('Error fetching installation approvals:', error);
      setInstallationApprovals([]);
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
      const firstPage = await customerService.getCustomers({ per_page: 100, page: 1 });
      const allCustomers = [...firstPage.data];
      for (let page = 2; page <= (firstPage.meta?.last_page || 1); page += 1) {
        const response = await customerService.getCustomers({ per_page: 100, page });
        allCustomers.push(...response.data);
      }
      setCustomers(allCustomers.sort((a, b) => a.full_name.localeCompare(b.full_name)));
    } catch (error) {
      console.error('Error fetching customers:', error);
    }
  };

  const handleCreateTicket = async (e: React.FormEvent) => {
    e.preventDefault();
    if (formData.ticket_type === 'installation') {
      try {
        const response = await api.post('/customer-portal/signup', installationForm);
        const application = response.data?.data;
        setShowCreateModal(false);
        setInstallationForm(emptyInstallationApplication);
        resetForm();
        await fetchTickets();
        await fetchInstallationApprovals();
        window.alert(
          `Installation application created.\n\nAccount: ${application?.account_number || 'Pending'}\nTemporary password: ${application?.password || 'Sent by email'}`,
        );
      } catch (error: any) {
        const errors = error.response?.data?.errors;
        const firstError = errors ? Object.values(errors).flat()[0] : null;
        window.alert(String(firstError || error.response?.data?.message || 'Could not create the installation application.'));
      }
      return;
    }
    if (!formData.customer_id) {
      window.alert('Select a customer before creating the ticket.');
      return;
    }
    try {
      const response = await ticketService.createTicket(formData);
      setShowCreateModal(false);
      await fetchTickets();
      if (formData.ticket_type === 'installation') await fetchInstallationApprovals();
      resetForm();
      window.alert(response.message);
    } catch (error: any) {
      console.error('Error creating ticket:', error);
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors).flat()[0] : null;
      window.alert(String(firstError || error.response?.data?.message || 'Could not create the ticket.'));
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

  const captureInstallationLocation = (): void => {
    if (!navigator.geolocation) {
      window.alert('This device cannot provide an installation location. You may continue without GPS coordinates.');
      return;
    }
    if (!window.confirm('Capture location only while you are at the client’s exact installation address. Continue?')) return;
    setInstallationLocating(true);
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => {
        setInstallationForm((current) => ({
          ...current,
          gps_coordinates: { latitude: coords.latitude, longitude: coords.longitude },
          location_accuracy_meters: coords.accuracy,
        }));
        setInstallationLocating(false);
      },
      () => {
        setInstallationLocating(false);
        window.alert('Location permission was not granted. You may continue without GPS coordinates.');
      },
      { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
    );
  };

  const handleDeleteTicket = async (ticket: Ticket): Promise<void> => {
    const confirmation = window.prompt(
      `Delete ${ticket.ticket_number}?\n\nThis removes the ticket and its comments/history. The customer, billing, payments, and network records are preserved.\n\nType the exact ticket number to confirm:`,
    );
    if (confirmation === null) return;

    try {
      const response = await ticketService.deleteTicket(ticket.id, confirmation.trim());
      setTickets((current) => current.filter((item) => item.id !== ticket.id));
      setInstallationApprovals((current) => current.filter((item) => item.id !== ticket.id));
      if (selectedTicket?.id === ticket.id) {
        setSelectedTicket(null);
        setShowViewModal(false);
      }
      window.alert(response.message);
    } catch (error: any) {
      window.alert(error.response?.data?.message || 'The ticket could not be deleted.');
    }
  };

  const reviewInstallation = async (ticket: Ticket, decision: 'approve' | 'return') => {
    let payload: Record<string, string> = {};
    if (decision === 'return') {
      const reason = window.prompt('Explain what the technician must correct:');
      if (reason === null) return;
      if (reason.trim().length < 5) {
        window.alert('Please enter a clear correction reason.');
        return;
      }
      payload = { reason: reason.trim() };
    } else {
      if (!ticket.installation_validation?.can_approve) {
        window.alert(ticket.installation_validation?.message || 'The MAC address must match one current bound DHCP lease before registration.');
        return;
      }
      if (!window.confirm(`Approve ${ticket.ticket_number}, bind MAC ${ticket.installation_mac}, and register this customer?`)) return;
    }

    try {
      const response = await api.post(`/tickets/${ticket.id}/installation/${decision}`, payload);
      setShowViewModal(false);
      setSelectedTicket(null);
      await fetchTickets();
      await fetchInstallationApprovals();
      window.alert(response.data?.message || 'Installation reviewed.');
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors).flat()[0] : null;
      window.alert(String(firstError || error.response?.data?.message || 'Could not review this installation.'));
    }
  };

  const refreshInstallationValidation = async (ticket: Ticket) => {
    try {
      const refreshed = await ticketService.getTicket(ticket.id);
      setSelectedTicket(refreshed);
      setInstallationApprovals((current) => current.map((item) => item.id === refreshed.id ? refreshed : item));
    } catch (error: any) {
      window.alert(error.response?.data?.message || 'Could not refresh the MAC validation status.');
    }
  };

  const correctInstallationMac = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!selectedTicket) return;

    try {
      const response = await ticketService.correctInstallationMac(selectedTicket.id, {
        mac_address: macCorrection.mac_address.trim(),
        reason: macCorrection.reason.trim(),
      });
      setSelectedTicket(response.ticket);
      setInstallationApprovals((current) => current.map((item) => item.id === response.ticket.id ? response.ticket : item));
      setShowMacCorrection(false);
      setMacCorrection({ mac_address: '', reason: '' });
      await fetchTickets();
      await fetchInstallationApprovals();
      window.alert(response.message);
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors).flat()[0] : null;
      window.alert(String(firstError || error.response?.data?.message || 'Could not correct the pending installation MAC.'));
    }
  };

  const resetForm = () => {
    setCustomerSearch('');
    setInstallationForm(emptyInstallationApplication);
    setFormData({
      customer_id: '',
      ticket_type: 'other',
      subject: '',
      description: '',
      priority: 'medium',
      category: 'general',
    });
  };

  const visibleCustomerChoices = useMemo(() => {
    const term = customerSearch.trim().toLocaleLowerCase();
    if (!term) return customers;

    return customers.filter((customer) => [
      customer.full_name,
      customer.account_number,
      customer.address,
      customer.contact_number,
      customer.email,
    ].some((value) => String(value ?? '').toLocaleLowerCase().includes(term)));
  }, [customerSearch, customers]);

  const selectedFormCustomer = useMemo(
    () => customers.find((customer) => customer.id === formData.customer_id) ?? null,
    [customers, formData.customer_id],
  );

  const getStatusBadge = (status: string) => {
    const badges = {
      unclaimed: { bg: 'bg-slate-100', text: 'text-slate-700', icon: AlertCircle },
      claimed: { bg: 'bg-blue-100', text: 'text-blue-800', icon: Clock },
      open: { bg: 'bg-blue-100', text: 'text-blue-800', icon: AlertCircle },
      in_progress: { bg: 'bg-yellow-100', text: 'text-yellow-800', icon: Clock },
      resolved: { bg: 'bg-green-100', text: 'text-green-800', icon: CheckCircle },
      closed: { bg: 'bg-gray-100', text: 'text-gray-600', icon: XCircle },
      waiting_admin_approval: { bg: 'bg-violet-100', text: 'text-violet-800', icon: ClipboardCheck },
      returned_for_correction: { bg: 'bg-orange-100', text: 'text-orange-800', icon: AlertCircle },
      registered: { bg: 'bg-emerald-100', text: 'text-emerald-800', icon: CheckCircle },
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
              <option value="waiting_admin_approval">Waiting Admin Approval</option>
              <option value="returned_for_correction">Returned for Correction</option>
              <option value="registered">Registered</option>
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
                  <tr key={change.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/80">
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

        {canApproveInstallations && <section className="mb-6" data-testid="installation-approval-queue">
          <div className="mb-3 flex items-center gap-2">
            <ClipboardCheck className="h-5 w-5 text-violet-600" />
            <div><h2 className="font-semibold text-gray-900">Installation Approval</h2><p className="text-sm text-gray-600">Review technician-completed installations before customer registration and MikroTik synchronization.</p></div>
          </div>
          <div className="overflow-x-auto rounded-xl border border-violet-200 bg-white shadow-sm">
            <table className="w-full text-sm"><thead className="bg-violet-50 text-xs uppercase tracking-wider text-violet-700"><tr><th className="px-4 py-3 text-left">Ticket</th><th className="px-4 py-3 text-left">Customer</th><th className="px-4 py-3 text-left">Technician</th><th className="px-4 py-3 text-left">MAC address</th><th className="px-4 py-3 text-left">Lease status</th><th className="px-4 py-3 text-left">Submitted</th><th className="px-4 py-3 text-right">Action</th></tr></thead><tbody className="divide-y divide-violet-100">{installationApprovals.map((ticket) => <tr key={ticket.id}><td className="px-4 py-3 font-semibold text-gray-900">{ticket.ticket_number}</td><td className="px-4 py-3"><p className="font-medium">{ticket.customer?.full_name || 'Unknown'}</p><p className="text-xs text-gray-500">{ticket.customer?.address || 'No address'}</p></td><td className="px-4 py-3">{ticket.assigned_technician?.name || 'Unassigned'}</td><td className="px-4 py-3 font-mono">{ticket.installation_mac || 'Not supplied'}</td><td className="px-4 py-3"><span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${ticket.installation_validation?.can_approve ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'}`}>{ticket.installation_validation?.can_approve ? 'MATCHED' : 'NOT MATCHED'}</span></td><td className="px-4 py-3 text-xs text-gray-500">{ticket.submitted_for_approval_at ? new Date(ticket.submitted_for_approval_at).toLocaleString() : '—'}</td><td className="px-4 py-3 text-right"><button onClick={() => { setSelectedTicket(ticket); setShowViewModal(true); }} className="font-semibold text-violet-700 hover:underline">Review</button></td></tr>)}{installationApprovals.length === 0 && <tr><td colSpan={7} className="px-4 py-8 text-center text-gray-500">No installations are waiting for approval.</td></tr>}</tbody></table>
          </div>
        </section>}

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
                  <tr key={ticket.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/80 transition-colors">
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
                      {ticket.ticket_type === 'installation' ? 'installation application' : ticket.ticket_type === 'repair' ? 'repair' : ticket.category.replace('_', ' ')}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      {getPriorityBadge(ticket.priority)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      {getStatusBadge(ticket.workflow_status || ticket.status)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {new Date(ticket.created_at).toLocaleDateString()}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm">
                      <div className="flex items-center justify-end gap-3">
                      <button
                        onClick={() => {
                          setSelectedTicket(ticket);
                          setShowViewModal(true);
                        }}
                        className="text-blue-600 hover:text-blue-900 font-medium"
                      >
                        View
                      </button>
                      {canDeleteTickets && (
                        <button
                          type="button"
                          onClick={() => void handleDeleteTicket(ticket)}
                          className="inline-flex items-center gap-1 font-medium text-rose-600 hover:text-rose-800"
                        >
                          <Trash2 className="h-4 w-4" /> Delete
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

        {/* Create Ticket Modal */}
        {showCreateModal && (
          <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
              <div className="p-6">
                <h2 className="text-xl font-bold text-gray-900 mb-4">Create New Ticket</h2>
                <form onSubmit={handleCreateTicket}>
                  <div className="space-y-4">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Ticket type</label>
                      <select
                        value={formData.ticket_type}
                        onChange={(event) => {
                          const ticketType = event.target.value as 'repair' | 'installation' | 'other';
                          setFormData((current) => ({
                            ...current,
                            ticket_type: ticketType,
                            subject: ticketType === 'installation'
                              ? 'New Installation Application — approval and binding required'
                              : current.ticket_type === 'installation' ? '' : current.subject,
                            category: ticketType === 'repair'
                              ? 'technical'
                              : ticketType === 'installation' ? 'general' : current.category,
                          }));
                        }}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                      >
                        <option value="other">General / billing concern</option>
                        <option value="repair">Repair / no-internet concern</option>
                        <option value="installation">New Installation Application</option>
                      </select>
                      {formData.ticket_type === 'installation' && (
                        <p className="mt-1 text-xs text-blue-700 dark:text-cyan-300">
                          This enters the technician claim, installation MAC/notes, and administrator approval workflow.
                        </p>
                      )}
                    </div>

                    {formData.ticket_type === 'installation' ? (
                      <div className="space-y-4 rounded-xl border border-blue-200 bg-blue-50/60 p-4 dark:border-cyan-800 dark:bg-slate-900/60">
                        <div className="grid gap-4 sm:grid-cols-2">
                          <label className="text-sm font-medium text-gray-700 dark:text-slate-200">Applicant full name *
                            <input required value={installationForm.full_name} onChange={(event) => setInstallationForm((current) => ({ ...current, full_name: event.target.value }))} className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="Juan Dela Cruz" />
                          </label>
                          <label className="text-sm font-medium text-gray-700 dark:text-slate-200">Email address *
                            <input required type="email" value={installationForm.email} onChange={(event) => setInstallationForm((current) => ({ ...current, email: event.target.value }))} className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="client@example.com" />
                          </label>
                          <label className="text-sm font-medium text-gray-700 dark:text-slate-200">Contact number *
                            <input required value={installationForm.contact_number} onChange={(event) => setInstallationForm((current) => ({ ...current, contact_number: event.target.value }))} className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="09XXXXXXXXX" />
                          </label>
                          <label className="text-sm font-medium text-gray-700 dark:text-slate-200">Preferred service plan *
                            <select required value={installationForm.service_plan_id} onChange={(event) => setInstallationForm((current) => ({ ...current, service_plan_id: event.target.value }))} className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
                              <option value="">Select plan</option>
                              {installationPlans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name} · {plan.download_speed}/{plan.upload_speed} Mbps · ₱{Number(plan.price).toLocaleString('en-PH')}/mo</option>)}
                            </select>
                          </label>
                        </div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-slate-200">Installation address *
                          <textarea required rows={2} value={installationForm.address} onChange={(event) => setInstallationForm((current) => ({ ...current, address: event.target.value }))} className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="Street, barangay, municipality, province" />
                        </label>
                        <div className="rounded-lg border border-blue-200 bg-white/80 p-3 dark:border-slate-700 dark:bg-slate-800">
                          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div><p className="text-sm font-semibold text-gray-900 dark:text-slate-100">Exact installation location (optional)</p><p className="text-xs text-gray-600 dark:text-slate-300">Capture only while physically at the installation address.</p></div>
                            <button type="button" disabled={installationLocating} onClick={captureInstallationLocation} className="rounded-lg border border-blue-300 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50 disabled:opacity-50 dark:text-cyan-300 dark:hover:bg-slate-700">{installationLocating ? 'Capturing…' : installationForm.gps_coordinates ? 'Update GPS' : 'Capture GPS'}</button>
                          </div>
                          {installationForm.gps_coordinates && <p className="mt-2 font-mono text-xs text-emerald-700 dark:text-emerald-300">{installationForm.gps_coordinates.latitude.toFixed(6)}, {installationForm.gps_coordinates.longitude.toFixed(6)} · ±{Math.round(installationForm.location_accuracy_meters || 0)} m</p>}
                        </div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-slate-200">Application notes (optional)
                          <textarea rows={3} value={installationForm.notes} onChange={(event) => setInstallationForm((current) => ({ ...current, notes: event.target.value }))} className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="Landmark, preferred contact time, or relevant installation details" />
                        </label>
                        <p className="text-xs text-blue-800 dark:text-cyan-300">A pending customer account and installation ticket will be created. The client receives portal credentials through the existing welcome-email system.</p>
                      </div>
                    ) : (<>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                      <div className="rounded-lg border border-gray-300 bg-white">
                        <div className="relative border-b border-gray-200">
                          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                          <input
                            type="search"
                            value={customerSearch}
                            onChange={(event) => setCustomerSearch(event.target.value)}
                            placeholder="Search name, account, address, phone, or email"
                            className="w-full rounded-t-lg py-2.5 pl-9 pr-3 text-sm text-gray-900 outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500"
                            autoFocus
                          />
                        </div>
                        {selectedFormCustomer && (
                          <div className="border-b border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-900">
                            <span className="font-semibold">Selected:</span> {selectedFormCustomer.full_name} · {selectedFormCustomer.account_number}
                          </div>
                        )}
                        <div className="max-h-64 overflow-y-auto" role="listbox" aria-label="All customers">
                          {visibleCustomerChoices.map((customer) => {
                            const selected = customer.id === formData.customer_id;
                            return (
                              <button
                                key={customer.id}
                                type="button"
                                role="option"
                                aria-selected={selected}
                                onClick={() => setFormData({ ...formData, customer_id: customer.id })}
                                className={`block w-full border-b border-gray-100 px-3 py-2.5 text-left text-sm transition last:border-b-0 ${selected ? 'bg-blue-600 text-white dark:bg-cyan-400 dark:text-slate-950' : 'text-gray-900 hover:bg-blue-50 dark:text-slate-100 dark:hover:bg-slate-800'}`}
                              >
                                <span className="block font-semibold">{customer.full_name}</span>
                                <span className={`block text-xs ${selected ? 'text-blue-100 dark:text-slate-800' : 'text-gray-500 dark:text-slate-300'}`}>
                                  {customer.account_number}{customer.address ? ` · ${customer.address}` : ''}
                                </span>
                              </button>
                            );
                          })}
                          {visibleCustomerChoices.length === 0 && (
                            <p className="px-3 py-6 text-center text-sm text-gray-500">No customer matches this search.</p>
                          )}
                        </div>
                        <div className="border-t border-gray-200 px-3 py-2 text-xs text-gray-500">
                          Showing {visibleCustomerChoices.length} of {customers.length} customers
                        </div>
                      </div>
                      {!formData.customer_id && <p className="mt-1 text-xs text-red-600">Select one customer to create the ticket.</p>}
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
                    </>)}
                  </div>

                  <div className="mt-6 flex justify-end gap-3">
                    <button
                      type="button"
                      onClick={() => {
                        setShowCreateModal(false);
                        resetForm();
                      }}
                      className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors"
                    >
                      Cancel
                    </button>
                    <button
                      type="submit"
                      disabled={formData.ticket_type === 'installation'
                        ? !installationForm.full_name.trim() || !installationForm.email.trim() || !installationForm.contact_number.trim() || !installationForm.address.trim() || !installationForm.service_plan_id
                        : !formData.customer_id}
                      className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      {formData.ticket_type === 'installation' ? 'Create Installation Application' : 'Create Ticket'}
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
                    {getStatusBadge(selectedTicket.workflow_status || selectedTicket.status)}
                    {getPriorityBadge(selectedTicket.priority)}
                  </div>

                  <div className="grid grid-cols-2 gap-4 p-4 bg-gray-50 rounded-lg">
                    <div>
                      <p className="text-sm text-gray-600">Customer</p>
                      <p className="font-medium">{selectedTicket.customer?.full_name}</p>
                    </div>
                    <div>
                      <p className="text-sm text-gray-600">Category</p>
                      <p className="font-medium capitalize">{selectedTicket.ticket_type === 'installation' ? 'Installation application' : selectedTicket.ticket_type === 'repair' ? 'Repair' : selectedTicket.category.replace('_', ' ')}</p>
                    </div>
                    <div>
                      <p className="text-sm text-gray-600">Created</p>
                      <p className="font-medium">{new Date(selectedTicket.created_at).toLocaleString()}</p>
                    </div>
                    <div>
                      <p className="text-sm text-gray-600">Assigned To</p>
                      <p className="font-medium">{selectedTicket.assigned_technician?.name || 'Unassigned'}</p>
                    </div>
                  </div>

                  {(selectedTicket.client_notes || selectedTicket.customer?.notes) && (
                    <div className="rounded-xl border border-blue-200 bg-blue-50 p-4">
                      <h3 className="font-semibold text-blue-950">Client notes</h3>
                      <p className="mt-1 whitespace-pre-wrap text-sm text-blue-900">{selectedTicket.client_notes || selectedTicket.customer?.notes}</p>
                    </div>
                  )}

                  <div>
                    <h3 className="font-medium text-gray-900 mb-2">Description</h3>
                    <p className="text-gray-700 whitespace-pre-wrap">{selectedTicket.description}</p>
                  </div>

                  {selectedTicket.ticket_type === 'installation' && (
                    <div className="rounded-xl border border-violet-200 bg-violet-50 p-4">
                      <h3 className="font-semibold text-violet-950">Installation validation</h3>
                      <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt className="text-violet-700">Workflow</dt><dd className="font-semibold capitalize">{selectedTicket.workflow_status.replaceAll('_', ' ')}</dd></div>
                        <div><dt className="text-violet-700">Technician</dt><dd className="font-semibold">{selectedTicket.assigned_technician?.name || 'Unclaimed'}</dd></div>
                        <div><dt className="text-violet-700">Submitted MAC</dt><dd className="font-mono font-semibold">{selectedTicket.installation_mac || 'Not submitted'}</dd></div>
                        <div><dt className="text-violet-700">Submitted</dt><dd>{selectedTicket.submitted_for_approval_at ? new Date(selectedTicket.submitted_for_approval_at).toLocaleString() : 'Not submitted'}</dd></div>
                      </dl>
                      {selectedTicket.installation_notes && <div className="mt-3"><p className="text-xs font-semibold uppercase text-violet-700">Technician notes</p><p className="mt-1 whitespace-pre-wrap text-sm text-violet-950">{selectedTicket.installation_notes}</p></div>}
                      {selectedTicket.return_reason && <p className="mt-3 rounded-lg bg-orange-100 p-3 text-sm text-orange-900"><strong>Returned correction:</strong> {selectedTicket.return_reason}</p>}
                      {selectedTicket.workflow_status === 'waiting_admin_approval' && <div className={`mt-4 rounded-xl border p-4 ${selectedTicket.installation_validation?.can_approve ? 'border-emerald-300 bg-emerald-50' : 'border-rose-300 bg-rose-50'}`}><div className="flex flex-wrap items-start justify-between gap-3"><div><p className={`font-bold ${selectedTicket.installation_validation?.can_approve ? 'text-emerald-800' : 'text-rose-800'}`}>{selectedTicket.installation_validation?.can_approve ? '✓ MAC MATCHED — READY TO REGISTER' : '✕ MAC NOT READY — REGISTRATION BLOCKED'}</p><p className="mt-1 text-sm text-gray-700">{selectedTicket.installation_validation?.message || 'Checking the current DHCP lease record is required.'}</p></div><button onClick={() => void refreshInstallationValidation(selectedTicket)} className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">Refresh MAC check</button></div>{selectedTicket.installation_validation?.lease && <dl className="mt-3 grid gap-2 border-t border-black/10 pt-3 text-sm sm:grid-cols-2"><div><dt className="text-gray-500">Lease IP</dt><dd className="font-semibold">{selectedTicket.installation_validation.lease.ip_address || '—'}</dd></div><div><dt className="text-gray-500">Router</dt><dd className="font-semibold">{selectedTicket.installation_validation.lease.router_name || '—'}</dd></div><div><dt className="text-gray-500">DHCP hostname</dt><dd>{selectedTicket.installation_validation.lease.hostname || '—'}</dd></div><div><dt className="text-gray-500">DHCP comment</dt><dd>{selectedTicket.installation_validation.lease.comment || '—'}</dd></div></dl>}</div>}
                      {canApproveInstallations && selectedTicket.workflow_status === 'waiting_admin_approval' && <div className="mt-4 space-y-3"><div className="flex flex-wrap gap-2"><button disabled={!selectedTicket.installation_validation?.can_approve} title={!selectedTicket.installation_validation?.can_approve ? 'A unique current bound DHCP lease match is required.' : undefined} onClick={() => void reviewInstallation(selectedTicket, 'approve')} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-gray-400">APPROVE &amp; REGISTER CUSTOMER</button><button type="button" onClick={() => { setMacCorrection({ mac_address: selectedTicket.installation_mac || '', reason: '' }); setShowMacCorrection((current) => !current); }} className="rounded-lg border border-violet-300 bg-white px-4 py-2 text-sm font-semibold text-violet-800 hover:bg-violet-100">CORRECT PENDING MAC</button><button onClick={() => void reviewInstallation(selectedTicket, 'return')} className="rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">RETURN FOR CORRECTION</button></div>{showMacCorrection && <form onSubmit={correctInstallationMac} className="rounded-xl border border-violet-300 bg-white p-4 shadow-sm"><div className="flex flex-wrap items-start justify-between gap-2"><div><h4 className="font-semibold text-violet-950">Correct pending installation MAC</h4><p className="mt-1 text-xs text-gray-600">This changes the installation ticket only. The customer, DHCP lease, and MikroTik configuration stay unchanged until safe registration approval.</p></div><button type="button" onClick={() => setShowMacCorrection(false)} className="text-xs font-semibold text-gray-500 hover:text-gray-800">Cancel</button></div><div className="mt-3 grid gap-3 sm:grid-cols-2"><label className="text-sm font-medium text-gray-700">Correct MAC address<input required value={macCorrection.mac_address} onChange={(event) => setMacCorrection((current) => ({ ...current, mac_address: event.target.value }))} placeholder="88:65:9F:9A:4D:BB" className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm uppercase focus:border-violet-500 focus:outline-none" /></label><label className="text-sm font-medium text-gray-700">Correction reason<textarea required minLength={5} value={macCorrection.reason} onChange={(event) => setMacCorrection((current) => ({ ...current, reason: event.target.value }))} placeholder="Verified the ONU label and current DHCP lease." className="mt-1 min-h-20 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-violet-500 focus:outline-none" /></label></div><button type="submit" className="mt-3 rounded-lg bg-violet-700 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-800">SAVE CORRECTED MAC &amp; RECHECK</button></form>}</div>}
                    </div>
                  )}

                  {!!selectedTicket.histories?.length && <div><h3 className="mb-2 font-medium text-gray-900">Audit history</h3><ol className="space-y-2">{selectedTicket.histories.map((history) => <li key={history.id} className="border-l-2 border-blue-200 pl-3 text-sm"><p className="font-medium capitalize">{history.action.replaceAll('_', ' ')} · {history.user?.name || 'System'}</p><p className="text-xs text-gray-500">{new Date(history.created_at).toLocaleString()}{history.new_status ? ` · ${history.new_status.replaceAll('_', ' ')}` : ''}</p>{history.notes && <p className="mt-1 text-gray-700">{history.notes}</p>}</li>)}</ol></div>}

                  {selectedTicket.ticket_type === 'other' && selectedTicket.status !== 'closed' && (
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
