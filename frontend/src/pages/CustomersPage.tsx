import React, { useEffect, useState, useCallback, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';
import { customerService, type ClientSetupAction, type CustomerUpdateImportPreview, type CustomerUpdateImportRowStatus } from '@/services/customerService';
import type { Customer } from '@/types/api';
import { logger } from '@/lib/logger';
import { monthlyDueDateLabel } from '@/lib/billingCycle';
import { Download, FileUp, Link2, MapPin } from 'lucide-react';
import { CustomerRegistrationImportModal } from '@/components/customers/CustomerRegistrationImportModal';

const CustomersPage: React.FC = () => {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [search, setSearch] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<string>('');
  const [sortOrder, setSortOrder] = useState<'newest' | 'oldest' | 'name_asc' | 'name_desc' | 'address_asc' | 'address_desc'>('newest');
  const [deleteTarget, setDeleteTarget] = useState<Customer | null>(null);
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState<boolean>(false);
  const [clientSetupOpen, setClientSetupOpen] = useState<boolean>(false);
  const [customerUpdateImportOpen, setCustomerUpdateImportOpen] = useState<boolean>(false);
  const [customerRegistrationImportOpen, setCustomerRegistrationImportOpen] = useState<boolean>(false);
  const [customerUpdateImportSource, setCustomerUpdateImportSource] = useState<'file' | 'google_sheet'>('file');
  const [customerUpdateImportFile, setCustomerUpdateImportFile] = useState<File | null>(null);
  const [customerUpdateImportSheetUrl, setCustomerUpdateImportSheetUrl] = useState<string>('');
  const [customerUpdateImportPreview, setCustomerUpdateImportPreview] = useState<CustomerUpdateImportPreview | null>(null);
  const [customerUpdateImportPreviewing, setCustomerUpdateImportPreviewing] = useState<boolean>(false);
  const [customerUpdateImportApplying, setCustomerUpdateImportApplying] = useState<boolean>(false);
  const [clientSetupAction, setClientSetupAction] = useState<ClientSetupAction | 'delete'>('billing_due_date');
  const [clientSetupSearch, setClientSetupSearch] = useState<string>('');
  const [setupInstallationDate, setSetupInstallationDate] = useState<string>('');
  const [setupDueDay, setSetupDueDay] = useState<string>('');
  const [setupUpdatesOpenInvoices, setSetupUpdatesOpenInvoices] = useState<boolean>(true);
  const [setupPreviousBalance, setSetupPreviousBalance] = useState<string>('');
  const [setupPreviousBalanceDueDate, setSetupPreviousBalanceDueDate] = useState<string>('');
  const [setupDiscount, setSetupDiscount] = useState<string>('');
  const [setupStatus, setSetupStatus] = useState<'active' | 'suspended' | 'expired' | 'pending'>('active');
  const [setupSharedAddress, setSetupSharedAddress] = useState<string>('');
  const [setupSubmitting, setSetupSubmitting] = useState<boolean>(false);
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [deleting, setDeleting] = useState<boolean>(false);
  const [exportingPdf, setExportingPdf] = useState<boolean>(false);
  const [notice, setNotice] = useState<string>('');
  const [error, setError] = useState<string>('');

  const fetchCustomers = useCallback(async (): Promise<void> => {
    try {
      setLoading(true);
      const params = new URLSearchParams();
      if (search) params.append('search', search);
      if (statusFilter) params.append('status', statusFilter);
      const [sortBy, sortDirection] = sortOrder === 'name_asc' ? ['name', 'asc']
        : sortOrder === 'name_desc' ? ['name', 'desc']
          : sortOrder === 'address_asc' ? ['address', 'asc']
            : sortOrder === 'address_desc' ? ['address', 'desc']
              : sortOrder === 'oldest' ? ['created_at', 'asc'] : ['created_at', 'desc'];
      params.append('sort_by', sortBy);
      params.append('sort_direction', sortDirection);
      
      params.set('per_page', '100');
      params.set('page', '1');
      const response = await api.get<{ data: Customer[]; meta?: { last_page?: number } }>(`/customers?${params.toString()}`);
      const allCustomers = [...response.data.data];
      const lastPage = response.data.meta?.last_page || 1;

      for (let page = 2; page <= lastPage; page += 1) {
        params.set('page', String(page));
        const pageResponse = await api.get<{ data: Customer[] }>(`/customers?${params.toString()}`);
        allCustomers.push(...pageResponse.data.data);
      }

      setCustomers(allCustomers);
    } catch (error) {
      logger.error('Failed to fetch customers', error);
    } finally {
      setLoading(false);
    }
  }, [search, statusFilter, sortOrder]);

  useEffect(() => {
    fetchCustomers();
  }, [fetchCustomers]);

  const sortedCustomers = useMemo(() => {
    const rows = [...customers];
    const compareText = (left?: string | null, right?: string | null) => String(left || '').localeCompare(String(right || ''), 'en-PH', { sensitivity: 'base', numeric: true });
    rows.sort((left, right) => {
      if (sortOrder === 'name_asc') return compareText(left.full_name, right.full_name);
      if (sortOrder === 'name_desc') return compareText(right.full_name, left.full_name);
      if (sortOrder === 'address_asc') return compareText(left.address, right.address) || compareText(left.full_name, right.full_name);
      if (sortOrder === 'address_desc') return compareText(right.address, left.address) || compareText(left.full_name, right.full_name);
      const leftTime = new Date(left.created_at).getTime() || 0;
      const rightTime = new Date(right.created_at).getTime() || 0;
      return sortOrder === 'oldest' ? leftTime - rightTime : rightTime - leftTime;
    });
    return rows;
  }, [customers, sortOrder]);

  const handleConfirmDelete = async (): Promise<void> => {
    if (!deleteTarget) return;
    setDeleting(true);
    setError('');
    try {
      await customerService.deleteCustomer(deleteTarget.id);
      setNotice(`Customer "${deleteTarget.full_name}" deleted.`);
      setCustomers((prev) => prev.filter((c) => c.id !== deleteTarget.id));
      setSelectedIds((prev) => {
        const next = new Set(prev);
        next.delete(deleteTarget.id);
        return next;
      });
      setDeleteTarget(null);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to delete customer');
      logger.error('Failed to delete customer', err);
    } finally {
      setDeleting(false);
    }
  };

  const handleBulkDelete = async (): Promise<void> => {
    if (selectedIds.size === 0) return;
    setDeleting(true);
    setError('');
    try {
      const ids = Array.from(selectedIds);
      const res = await customerService.bulkDeleteCustomers(ids);
      setNotice(`${res.deleted} customer(s) deleted.`);
      setCustomers((prev) => prev.filter((c) => !selectedIds.has(c.id)));
      setSelectedIds(new Set());
      setBulkDeleteOpen(false);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to delete customers');
      logger.error('Failed to bulk-delete customers', err);
    } finally {
      setDeleting(false);
    }
  };

  const handleDownloadPdf = async (): Promise<void> => {
    setExportingPdf(true);
    setError('');
    try {
      const pdf = await customerService.downloadCustomersPdf({
        ...(search.trim() ? { search: search.trim() } : {}),
        ...(statusFilter ? { status: statusFilter } : {}),
      });
      const url = window.URL.createObjectURL(pdf);
      const link = document.createElement('a');
      const date = new Date().toISOString().slice(0, 10);
      link.href = url;
      link.download = `solarnet-customer-register-${date}.pdf`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
      setNotice(`Customer register PDF downloaded for ${customers.length} matching customer${customers.length === 1 ? '' : 's'}.`);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Unable to download the customer register PDF.');
      logger.error('Failed to download customer register PDF', err);
    } finally {
      setExportingPdf(false);
    }
  };

  const closeCustomerUpdateImport = (): void => {
    setCustomerUpdateImportOpen(false);
    setCustomerUpdateImportPreview(null);
    setCustomerUpdateImportFile(null);
    setCustomerUpdateImportSheetUrl('');
  };

  const handleCustomerUpdateImportPreview = async (): Promise<void> => {
    setCustomerUpdateImportPreviewing(true);
    setError('');
    try {
      if (customerUpdateImportSource === 'file' && !customerUpdateImportFile) {
        throw new Error('Choose an XLSX, XLS, or CSV file first.');
      }
      if (customerUpdateImportSource === 'google_sheet' && !customerUpdateImportSheetUrl.trim()) {
        throw new Error('Paste the shared Google Sheets link first.');
      }
      const preview = await customerService.previewCustomerUpdateImport(
        customerUpdateImportSource === 'file'
          ? { file: customerUpdateImportFile ?? undefined }
          : { googleSheetUrl: customerUpdateImportSheetUrl },
      );
      setCustomerUpdateImportPreview(preview);
    } catch (err: any) {
      const message = err?.response?.data?.message || err?.message || 'Unable to prepare the customer update preview.';
      setError(message);
      logger.error('Failed to preview customer update import', err);
    } finally {
      setCustomerUpdateImportPreviewing(false);
    }
  };

  const handleCustomerUpdateImportApply = async (): Promise<void> => {
    if (!customerUpdateImportPreview || customerUpdateImportPreview.summary.ready === 0) return;
    setCustomerUpdateImportApplying(true);
    setError('');
    try {
      const response = await customerService.applyCustomerUpdateImport(customerUpdateImportPreview.preview_token);
      const skipped = response.data.skipped.length > 0 ? ` ${response.data.skipped.length} row(s) were skipped safely.` : '';
      setNotice(`${response.message}${skipped}`);
      closeCustomerUpdateImport();
      await fetchCustomers();
    } catch (err: any) {
      const message = err?.response?.data?.message || 'Unable to apply the customer update import.';
      setError(message);
      logger.error('Failed to apply customer update import', err);
    } finally {
      setCustomerUpdateImportApplying(false);
    }
  };

  const setupCustomers = customers.filter((customer) => {
    const needle = clientSetupSearch.trim().toLowerCase();
    if (!needle) return true;
    return [customer.full_name, customer.account_number, customer.address]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(needle));
  });

  const toggleSetupCustomers = (): void => {
    const filteredIds = setupCustomers.map((customer) => customer.id);
    if (filteredIds.length === 0) return;
    const allFilteredSelected = filteredIds.every((id) => selectedIds.has(id));
    setSelectedIds((previous) => {
      const next = new Set(previous);
      filteredIds.forEach((id) => {
        if (allFilteredSelected) next.delete(id);
        else next.add(id);
      });
      return next;
    });
  };

  const handleClientSetup = async (): Promise<void> => {
    if (selectedIds.size === 0) {
      setError('Select at least one client before applying a setup action.');
      return;
    }

    const selectedCustomerIds = Array.from(selectedIds);
    setSetupSubmitting(true);
    setError('');
    try {
      if (clientSetupAction === 'delete') {
        const response = await customerService.bulkDeleteCustomers(selectedCustomerIds);
        setNotice(`${response.deleted} client record(s) archived.`);
        setCustomers((previous) => previous.filter((customer) => !selectedIds.has(customer.id)));
      } else {
        if (clientSetupAction === 'installation_date' && !setupInstallationDate) {
          throw new Error('Choose the clients’ original installation date.');
        }
        if (clientSetupAction === 'billing_due_date' && !setupDueDay) {
          throw new Error('Choose the clients’ monthly billing due day.');
        }
        if (clientSetupAction === 'previous_balance' && (!setupPreviousBalance || !setupPreviousBalanceDueDate)) {
          throw new Error('Enter the previous balance and its due date.');
        }
        if (clientSetupAction === 'discount' && !setupDiscount) {
          throw new Error('Enter the discount amount.');
        }
        if (clientSetupAction === 'address_updates' && !setupSharedAddress.trim()) {
          throw new Error('Enter the address to apply to the selected clients.');
        }

        const response = await customerService.bulkSetupCustomers({
          customer_ids: selectedCustomerIds,
          action: clientSetupAction,
          ...(clientSetupAction === 'installation_date' ? {
            installation_date: setupInstallationDate,
          } : {}),
          ...(clientSetupAction === 'billing_due_date' ? {
            billing_cycle_day: Number(setupDueDay),
            update_open_invoices: setupUpdatesOpenInvoices,
          } : {}),
          ...(clientSetupAction === 'previous_balance' ? {
            previous_balance: Number(setupPreviousBalance),
            previous_balance_due_date: setupPreviousBalanceDueDate,
          } : {}),
          ...(clientSetupAction === 'discount' ? { discount_amount: Number(setupDiscount) } : {}),
          ...(clientSetupAction === 'status' ? { status: setupStatus } : {}),
          ...(clientSetupAction === 'address_updates' ? {
            address_updates: selectedSetupCustomers.map((customer) => ({
              customer_id: customer.id,
              address: setupSharedAddress.trim(),
            })),
          } : {}),
        });
        const skipped = response.data.skipped.length > 0
          ? ` ${response.data.skipped.length} item(s) were skipped safely.`
          : '';
        setNotice(`${response.message}${skipped}`);
      }

      setSelectedIds(new Set());
      setSetupSharedAddress('');
      setClientSetupOpen(false);
      await fetchCustomers();
    } catch (err: any) {
      const message = err?.response?.data?.message || err?.message || 'Unable to apply the client setup.';
      setError(message);
      logger.error('Failed to apply client setup', err);
    } finally {
      setSetupSubmitting(false);
    }
  };

  const toggleOne = (id: string): void => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleAll = (): void => {
    setSelectedIds((prev) => {
      if (prev.size === customers.length && customers.length > 0) return new Set();
      return new Set(customers.map((c) => c.id));
    });
  };

  const allSelected = customers.length > 0 && selectedIds.size === customers.length;
  const someSelected = selectedIds.size > 0 && selectedIds.size < customers.length;
  const allVisibleSetupCustomersSelected = setupCustomers.length > 0
    && setupCustomers.every((customer) => selectedIds.has(customer.id));
  const selectedSetupCustomers = customers.filter((customer) => selectedIds.has(customer.id));

  const getStatusBadge = (status: string): JSX.Element => {
    const colors = {
      active: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
      suspended: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
      expired: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
      pending: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    };

    return (
      <span className={`px-2.5 py-0.5 rounded-full text-xs font-medium ${colors[status as keyof typeof colors]}`}>
        {status.charAt(0).toUpperCase() + status.slice(1)}
      </span>
    );
  };

  const importStatusLabel = (status: CustomerUpdateImportRowStatus): string => ({
    ready: 'Ready to update',
    unchanged: 'Already current',
    no_match: 'No match',
    ambiguous: 'Duplicate name',
    pending: 'Pending application',
    invalid: 'Needs correction',
  }[status]);

  const importStatusClass = (status: CustomerUpdateImportRowStatus): string => ({
    ready: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200',
    unchanged: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    no_match: 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200',
    ambiguous: 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200',
    pending: 'bg-blue-100 text-blue-800 dark:bg-blue-950/50 dark:text-blue-200',
    invalid: 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-200',
  }[status]);

  return (
    <DashboardLayout>
      <div className="space-y-6">
        {/* Alerts */}
        {notice && (
          <div className="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-md p-3 text-sm text-emerald-800 dark:text-emerald-200 flex justify-between items-center" data-testid="customer-notice">
            <span>{notice}</span>
            <button onClick={() => setNotice('')} className="text-xs text-emerald-700 dark:text-emerald-300 hover:underline">dismiss</button>
          </div>
        )}
        {error && (
          <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-3 text-sm text-red-800 dark:text-red-200" data-testid="customer-error">
            {error}
          </div>
        )}
        {/* Header */}
        <div className="flex flex-col gap-3 sm:flex-row sm:justify-between sm:items-center">
          <div>
            <h1 className="text-3xl font-bold text-foreground">Customers</h1>
            <p className="text-muted-foreground mt-1">Manage your ISP subscribers</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => { setCustomerRegistrationImportOpen(true); setError(''); }}
              className="inline-flex items-center gap-2 px-4 py-2 border border-primary/40 text-primary bg-primary/5 rounded-md hover:bg-primary/10 transition-colors"
              data-testid="customer-registration-import-btn"
            >
              <FileUp className="h-4 w-4" />
              Import New Clients
            </button>
            <button
              type="button"
              onClick={handleDownloadPdf}
              disabled={exportingPdf || loading}
              className="inline-flex items-center gap-2 px-4 py-2 border border-primary/40 text-primary bg-primary/5 rounded-md hover:bg-primary/10 transition-colors disabled:cursor-not-allowed disabled:opacity-60"
              data-testid="download-customers-pdf"
            >
              <Download className="h-4 w-4" />
              {exportingPdf ? 'Preparing PDF…' : 'Download PDF'}
            </button>
            <button
              type="button"
              onClick={() => { setClientSetupOpen(true); setError(''); }}
              className="px-4 py-2 border border-primary/40 text-primary bg-primary/5 rounded-md hover:bg-primary/10 transition-colors"
              data-testid="client-setups-btn"
            >
              Client Setups
            </button>
            <button
              type="button"
              onClick={() => { setCustomerUpdateImportOpen(true); setError(''); }}
              className="inline-flex items-center gap-2 px-4 py-2 border border-primary/40 text-primary bg-primary/5 rounded-md hover:bg-primary/10 transition-colors"
              data-testid="customer-update-import-btn"
            >
              <FileUp className="h-4 w-4" />
              Import Updates
            </button>
            <Link
              to="/customers/create"
              className="px-4 py-2 bg-primary text-primary-foreground rounded-md hover:opacity-90 transition-opacity"
            >
              + Add Customer
            </Link>
          </div>
        </div>

        {/* Filters */}
        <div className="bg-card border border-border rounded-lg p-4">
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            {/* Search */}
            <div className="md:col-span-2">
              <input
                type="text"
                placeholder="Search by name, account, email, phone..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
              />
            </div>

            {/* Status Filter */}
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
            >
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="expired">Expired</option>
              <option value="pending">Pending</option>
            </select>

            <select
              value={sortOrder}
              onChange={(event) => setSortOrder(event.target.value as typeof sortOrder)}
              className="px-4 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
              aria-label="Sort customers"
            >
              <option value="newest">Newest to oldest</option>
              <option value="oldest">Oldest to newest</option>
              <option value="name_asc">Name: A to Z</option>
              <option value="name_desc">Name: Z to A</option>
              <option value="address_asc">Address: A to Z</option>
              <option value="address_desc">Address: Z to A</option>
            </select>
          </div>
        </div>

        {/* Bulk action bar */}
        {selectedIds.size > 0 && (
          <div
            className="flex items-center justify-between bg-primary/10 border border-primary/30 rounded-lg px-4 py-3"
            data-testid="bulk-action-bar"
          >
            <div className="text-sm text-foreground">
              <strong data-testid="bulk-selected-count">{selectedIds.size}</strong> customer{selectedIds.size === 1 ? '' : 's'} selected
            </div>
            <div className="flex gap-2">
              <button
                type="button"
                onClick={() => setSelectedIds(new Set())}
                className="px-3 py-1.5 text-sm bg-secondary text-secondary-foreground rounded-md hover:opacity-90"
                data-testid="bulk-clear-selection"
              >
                Clear
              </button>
              <button
                type="button"
                onClick={() => { setBulkDeleteOpen(true); setError(''); }}
                className="px-3 py-1.5 text-sm bg-red-600 text-white rounded-md hover:bg-red-700"
                data-testid="bulk-delete-btn"
              >
                Delete selected
              </button>
            </div>
          </div>
        )}

        {/* Customer Table */}
        <div className="bg-card border border-border rounded-lg overflow-hidden">
          {loading ? (
            <div className="p-8 text-center text-muted-foreground">Loading...</div>
          ) : customers.length === 0 ? (
            <div className="p-8 text-center text-muted-foreground">
              No customers found. Click &quot;Add Customer&quot; to create one.
            </div>
          ) : (<>
            <div className="max-h-[70vh] overflow-auto overscroll-contain">
              <table className="w-full">
                <thead className="bg-secondary sticky top-0 z-10">
                  <tr>
                    <th className="px-4 py-3 text-left w-10">
                      <input
                        ref={(el) => {
                          if (el) el.indeterminate = someSelected;
                        }}
                        type="checkbox"
                        checked={allSelected}
                        onChange={toggleAll}
                        className="w-4 h-4 rounded border-input cursor-pointer accent-primary"
                        data-testid="select-all-customers"
                        aria-label="Select all customers"
                      />
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-foreground uppercase tracking-wider">
                      Account Number
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-foreground uppercase tracking-wider">
                      Name
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-foreground uppercase tracking-wider">
                      Contact
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-foreground uppercase tracking-wider">
                      Address
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-foreground uppercase tracking-wider">
                      Service Plan
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-foreground uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-foreground uppercase tracking-wider">
                      Monthly Fee
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-foreground uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {sortedCustomers.map((customer) => (
                    <tr
                      key={customer.id}
                      className={`hover:bg-secondary/50 transition-colors ${selectedIds.has(customer.id) ? 'bg-primary/5' : ''}`}
                      data-testid={`customer-row-${customer.id}`}
                    >
                      <td className="px-4 py-4 whitespace-nowrap">
                        <input
                          type="checkbox"
                          checked={selectedIds.has(customer.id)}
                          onChange={() => toggleOne(customer.id)}
                          className="w-4 h-4 rounded border-input cursor-pointer accent-primary"
                          data-testid={`select-customer-${customer.id}`}
                          aria-label={`Select ${customer.full_name}`}
                        />
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-foreground">
                        {customer.account_number}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-foreground">
                        {customer.full_name}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">
                        <div>{customer.contact_number}</div>
                        {customer.email && <div className="text-xs">{customer.email}</div>}
                      </td>
                      <td className="px-6 py-4 text-sm text-muted-foreground min-w-56">
                        <div className="max-w-56 truncate" title={customer.address}>{customer.address}</div>
                        {customer.gps_coordinates && (
                          <a href={`https://www.google.com/maps/search/?api=1&query=${customer.gps_coordinates.latitude},${customer.gps_coordinates.longitude}`}
                            target="_blank" rel="noreferrer" className="mt-1 inline-flex items-center gap-1 text-xs text-primary hover:underline">
                            <MapPin className="h-3 w-3" /> Open map
                          </a>
                        )}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-foreground">
                        {customer.service_plan ? (
                          <div>
                            <div className="font-medium">{customer.service_plan.name}</div>
                            <div className="text-xs text-muted-foreground">
                              {customer.service_plan.download_speed}/{customer.service_plan.upload_speed} Mbps
                            </div>
                          </div>
                        ) : (
                          <span className="text-muted-foreground text-xs">No plan assigned</span>
                        )}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        {getStatusBadge(customer.status)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-foreground">
                        ₱{Number(customer.monthly_fee).toLocaleString()}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm">
                        <Link
                          to={`/customers/${customer.id}`}
                          className="text-primary hover:underline mr-3"
                          data-testid={`view-customer-${customer.id}`}
                        >
                          View
                        </Link>
                        {customer.gps_coordinates && <a href={`https://www.google.com/maps/dir/?api=1&destination=${customer.gps_coordinates.latitude},${customer.gps_coordinates.longitude}`} target="_blank" rel="noreferrer" className="mr-3 inline-flex items-center gap-1 text-primary hover:underline"><MapPin className="h-3.5 w-3.5" /> Navigate</a>}
                        <Link
                          to={`/customers/${customer.id}/edit`}
                          className="text-primary hover:underline mr-3"
                          data-testid={`edit-customer-${customer.id}`}
                        >
                          Edit
                        </Link>
                        <button
                          type="button"
                          onClick={() => { setDeleteTarget(customer); setError(''); }}
                          className="text-red-600 hover:underline dark:text-red-400"
                          data-testid={`delete-customer-${customer.id}`}
                        >
                          Delete
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="border-t border-border bg-muted/30 px-4 py-2 text-xs text-muted-foreground">
              Showing all {customers.length.toLocaleString('en-PH')} matching customer{customers.length === 1 ? '' : 's'} — scroll the list to view the rest.
            </div>
          </>)}
        </div>
      </div>

      {/* Spreadsheet / Google Sheet profile update import */}
      {customerUpdateImportOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-3 backdrop-blur-sm sm:p-4"
          role="dialog"
          aria-modal="true"
          aria-labelledby="customer-update-import-title"
          data-testid="customer-update-import-modal"
        >
          <div className="flex max-h-[calc(100vh-1.5rem)] w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-border bg-card shadow-2xl">
            <div className="border-b border-border px-4 py-4 sm:px-6">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <h2 id="customer-update-import-title" className="text-lg font-semibold text-foreground">Import customer address &amp; due-date updates</h2>
                  <p className="mt-1 max-w-4xl text-sm text-muted-foreground">
                    Upload an Excel/CSV file or load a shared Google Sheet. SolarNet matches an existing customer by exact name, a unique safe surname/reordered-name variation, or a small unique spelling correction, then uses the sheet to update the full name, home address, and monthly billing due day after your review.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={closeCustomerUpdateImport}
                  disabled={customerUpdateImportPreviewing || customerUpdateImportApplying}
                  className="self-start rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:bg-secondary hover:text-foreground disabled:opacity-50"
                >
                  Close
                </button>
              </div>
              <p className="mt-3 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs leading-5 text-blue-900 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-100">
                Required columns: <strong>Client Name</strong>, <strong>Address</strong>, and <strong>Due Date</strong>. “Customer Name” or “Full Name” also work. A unique two-part name can safely gain/reorder a surname, such as Rueza Jade → Pormida Rueza Jade. Small unique corrections such as Sofia Labadatory → Sophia Labadatory are marked for review. MAC address, DHCP lease, router, GPS coordinates, balance, invoices, plan, and installation date are never changed.
              </p>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
              {!customerUpdateImportPreview ? (
                <div className="mx-auto max-w-2xl space-y-5">
                  <div className="grid gap-3 sm:grid-cols-2">
                    <button
                      type="button"
                      onClick={() => setCustomerUpdateImportSource('file')}
                      className={`rounded-lg border p-4 text-left transition-colors ${customerUpdateImportSource === 'file' ? 'border-primary bg-primary/5' : 'border-border hover:bg-secondary/50'}`}
                    >
                      <FileUp className="mb-2 h-5 w-5 text-primary" />
                      <span className="block text-sm font-medium text-foreground">Excel or CSV file</span>
                      <span className="mt-1 block text-xs text-muted-foreground">XLSX, XLS, or CSV up to 10 MB.</span>
                    </button>
                    <button
                      type="button"
                      onClick={() => setCustomerUpdateImportSource('google_sheet')}
                      className={`rounded-lg border p-4 text-left transition-colors ${customerUpdateImportSource === 'google_sheet' ? 'border-primary bg-primary/5' : 'border-border hover:bg-secondary/50'}`}
                    >
                      <Link2 className="mb-2 h-5 w-5 text-primary" />
                      <span className="block text-sm font-medium text-foreground">Shared Google Sheet</span>
                      <span className="mt-1 block text-xs text-muted-foreground">Anyone with the link must be allowed to view.</span>
                    </button>
                  </div>

                  {customerUpdateImportSource === 'file' ? (
                    <label className="block rounded-lg border border-dashed border-border bg-muted/20 p-5 text-sm text-foreground">
                      <span className="font-medium">Choose the customer update sheet</span>
                      <input
                        type="file"
                        accept=".xlsx,.xls,.csv,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        onChange={(event) => setCustomerUpdateImportFile(event.target.files?.[0] ?? null)}
                        className="mt-3 block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-foreground hover:file:opacity-90"
                      />
                      <span className="mt-2 block text-xs text-muted-foreground">{customerUpdateImportFile ? customerUpdateImportFile.name : 'No file selected.'}</span>
                    </label>
                  ) : (
                    <label className="block text-sm font-medium text-foreground">
                      Google Sheets link
                      <input
                        type="url"
                        value={customerUpdateImportSheetUrl}
                        onChange={(event) => setCustomerUpdateImportSheetUrl(event.target.value)}
                        placeholder="https://docs.google.com/spreadsheets/d/..."
                        className="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                      />
                      <span className="mt-2 block text-xs font-normal text-muted-foreground">SolarNet downloads a read-only export from Google. Private sheets are not accessed.</span>
                    </label>
                  )}
                </div>
              ) : (
                <div className="space-y-4">
                  <div className="flex flex-col gap-3 rounded-lg border border-border bg-muted/20 p-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <p className="text-sm font-medium text-foreground">Preview: {customerUpdateImportPreview.source_label}</p>
                      <p className="text-xs text-muted-foreground">This review expires in {customerUpdateImportPreview.expires_in_minutes} minutes. Only exact, unique name-variation, or small unique spelling-correction matches can be applied.</p>
                    </div>
                    <button
                      type="button"
                      onClick={() => setCustomerUpdateImportPreview(null)}
                      disabled={customerUpdateImportApplying}
                      className="self-start rounded-md border border-input bg-background px-3 py-1.5 text-sm text-foreground hover:bg-secondary disabled:opacity-50"
                    >
                      Choose another source
                    </button>
                  </div>

                  <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
                    {[
                      ['Rows', customerUpdateImportPreview.summary.total, 'text-foreground'],
                      ['Ready', customerUpdateImportPreview.summary.ready, 'text-emerald-600 dark:text-emerald-400'],
                      ['Current', customerUpdateImportPreview.summary.unchanged, 'text-muted-foreground'],
                      ['No match', customerUpdateImportPreview.summary.no_match, 'text-amber-600 dark:text-amber-400'],
                      ['Duplicate', customerUpdateImportPreview.summary.ambiguous, 'text-amber-600 dark:text-amber-400'],
                      ['Pending', customerUpdateImportPreview.summary.pending, 'text-blue-600 dark:text-blue-400'],
                      ['Invalid', customerUpdateImportPreview.summary.invalid, 'text-red-600 dark:text-red-400'],
                    ].map(([label, value, color]) => (
                      <div key={String(label)} className="rounded-md border border-border bg-background px-3 py-2">
                        <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
                        <p className={`mt-0.5 text-lg font-semibold ${color}`}>{value}</p>
                      </div>
                    ))}
                  </div>

                  <div className="overflow-auto rounded-lg border border-border">
                    <table className="min-w-[920px] w-full text-left text-sm">
                      <thead className="sticky top-0 z-10 bg-secondary text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                          <th className="px-3 py-2">Row</th>
                          <th className="px-3 py-2">Imported client</th>
                          <th className="px-3 py-2">Matched customer</th>
                          <th className="px-3 py-2">Address</th>
                          <th className="px-3 py-2">Due day</th>
                          <th className="px-3 py-2">Result</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-border">
                        {customerUpdateImportPreview.rows.map((row) => (
                          <tr key={`${row.row}-${row.client_name}`} className="align-top">
                            <td className="px-3 py-3 text-muted-foreground">{row.row}</td>
                            <td className="px-3 py-3 font-medium text-foreground">{row.client_name || '—'}</td>
                            <td className="px-3 py-3 text-foreground">
                              <span className="block">{row.account_number || '—'}</span>
                              {row.matched_full_name && <span className="mt-1 block text-xs text-muted-foreground">Current: {row.matched_full_name}</span>}
                              {row.match_type === 'name_variation' && <span className="mt-1 inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-medium text-blue-800 dark:bg-blue-950/50 dark:text-blue-200">Safe name variation</span>}
                              {row.match_type === 'typo_variation' && <span className="mt-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-950/50 dark:text-amber-200">Spelling correction</span>}
                            </td>
                            <td className="max-w-72 px-3 py-3 text-muted-foreground">
                              <span className="block truncate" title={row.address}>{row.address || '—'}</span>
                              {row.current_address !== null && row.current_address !== row.address && <span className="mt-1 block text-xs">Current: {row.current_address || 'No address'}</span>}
                            </td>
                            <td className="px-3 py-3 text-muted-foreground">
                              {row.due_day ? `Every ${row.due_day}${row.due_day === 1 ? 'st' : row.due_day === 2 ? 'nd' : row.due_day === 3 ? 'rd' : 'th'}` : '—'}
                              {row.current_due_day !== null && row.current_due_day !== row.due_day && <span className="mt-1 block text-xs">Current: {row.current_due_day}</span>}
                            </td>
                            <td className="px-3 py-3">
                              <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${importStatusClass(row.status)}`}>{importStatusLabel(row.status)}</span>
                              <p className="mt-1 max-w-72 text-xs leading-4 text-muted-foreground">{row.reason}</p>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}
            </div>

            <div className="flex flex-col-reverse gap-2 border-t border-border px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
              <p className="text-xs text-muted-foreground">
                {customerUpdateImportPreview
                  ? `${customerUpdateImportPreview.summary.ready} exact match(es) can be updated. All other rows are excluded.`
                  : 'No data is changed until you generate a preview and apply its exact matches.'}
              </p>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={closeCustomerUpdateImport}
                  disabled={customerUpdateImportPreviewing || customerUpdateImportApplying}
                  className="rounded-md bg-secondary px-4 py-2 text-sm text-secondary-foreground hover:opacity-90 disabled:opacity-50"
                >
                  Cancel
                </button>
                {!customerUpdateImportPreview ? (
                  <button
                    type="button"
                    onClick={handleCustomerUpdateImportPreview}
                    disabled={customerUpdateImportPreviewing}
                    className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                  >
                    {customerUpdateImportPreviewing ? 'Reading sheet…' : 'Generate safe preview'}
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={handleCustomerUpdateImportApply}
                    disabled={customerUpdateImportApplying || customerUpdateImportPreview.summary.ready === 0}
                    className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    data-testid="customer-update-import-apply"
                  >
                    {customerUpdateImportApplying ? 'Applying…' : `Apply ${customerUpdateImportPreview.summary.ready} matched update(s)`}
                  </button>
                )}
              </div>
            </div>
          </div>
        </div>
      )}
      <CustomerRegistrationImportModal
        open={customerRegistrationImportOpen}
        onClose={() => setCustomerRegistrationImportOpen(false)}
        onApplied={(message) => { setNotice(message); setCustomerRegistrationImportOpen(false); void fetchCustomers(); }}
      />

      {/* Existing-client migration/setup modal */}
      {clientSetupOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-3 sm:p-4"
          data-testid="client-setups-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="client-setups-title"
        >
          <div className="w-full max-w-5xl max-h-[calc(100vh-1.5rem)] overflow-y-auto rounded-xl border border-border bg-card shadow-2xl">
            <div className="sticky top-0 z-10 border-b border-border bg-card px-4 py-4 sm:px-6">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <h2 id="client-setups-title" className="text-lg font-semibold text-foreground">Client Setups</h2>
                  <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                    One-time setup for clients that existed before SolarNet Billing. Select clients, then apply one controlled change.
                    The original installation date is the monthly due-date reference. You can also update a different address for each selected client.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => setClientSetupOpen(false)}
                  disabled={setupSubmitting}
                  className="self-start rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:bg-secondary hover:text-foreground disabled:opacity-50"
                >
                  Close
                </button>
              </div>
            </div>

            <div className="grid gap-5 p-4 sm:p-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(19rem,0.8fr)]">
              <section className="min-w-0">
                <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <h3 className="font-medium text-foreground">Select clients</h3>
                    <p className="text-xs text-muted-foreground">{selectedIds.size} selected. You can also select rows from the Customers table before opening this card.</p>
                  </div>
                  <button
                    type="button"
                    onClick={toggleSetupCustomers}
                    className="text-sm font-medium text-primary hover:underline"
                  >
                    {allVisibleSetupCustomersSelected ? 'Clear visible' : 'Select visible'}
                  </button>
                </div>
                <input
                  type="search"
                  value={clientSetupSearch}
                  onChange={(event) => setClientSetupSearch(event.target.value)}
                  placeholder="Find by client, account number, or address"
                  className="mb-3 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                />
                <div className="max-h-[42vh] overflow-y-auto rounded-lg border border-border divide-y divide-border">
                  {setupCustomers.length === 0 ? (
                    <p className="p-4 text-sm text-muted-foreground">No matching customers.</p>
                  ) : setupCustomers.map((customer) => (
                    <label
                      key={customer.id}
                      className="flex cursor-pointer items-start gap-3 px-3 py-3 hover:bg-secondary/50"
                    >
                      <input
                        type="checkbox"
                        checked={selectedIds.has(customer.id)}
                        onChange={() => toggleOne(customer.id)}
                        className="mt-0.5 h-4 w-4 rounded border-input accent-primary"
                      />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium text-foreground">{customer.full_name}</span>
                        <span className="block truncate text-xs text-muted-foreground">
                          {customer.account_number} · {customer.address || 'No address saved'} · {customer.status}
                        </span>
                      </span>
                    </label>
                  ))}
                </div>
              </section>

              <section className="rounded-xl border border-border bg-muted/20 p-4 sm:p-5">
                <h3 className="font-medium text-foreground">Choose setup action</h3>
                <select
                  value={clientSetupAction}
                  onChange={(event) => setClientSetupAction(event.target.value as ClientSetupAction | 'delete')}
                  className="mt-3 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                >
                  <option value="billing_due_date">Set monthly billing due day</option>
                  <option value="installation_date">Set original installation date</option>
                  <option value="address_updates">Update selected client addresses</option>
                  <option value="previous_balance">Add previous bill balance</option>
                  <option value="discount">Add discount to open invoices</option>
                  <option value="status">Set account status</option>
                  <option value="delete">Delete selected clients</option>
                </select>

                {clientSetupAction === 'billing_due_date' && (
                  <div className="mt-4 space-y-3">
                    <label className="block text-sm font-medium text-foreground">
                      Monthly due day
                      <select
                        value={setupDueDay}
                        onChange={(event) => setSetupDueDay(event.target.value)}
                        className="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                      >
                        <option value="">Select due day</option>
                        {Array.from({ length: 31 }, (_, index) => index + 1).map((day) => <option key={day} value={day}>{monthlyDueDateLabel(day).replace('Due date: every ', '')}</option>)}
                      </select>
                    </label>
                    <p className="rounded-md border border-blue-200 bg-blue-50 p-3 text-xs leading-5 text-blue-900 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-100">
                      This updates the real <code>billing_cycle_day</code> only. It does not alter the historical installation date. For example, choosing the 20th bills this client every 20th of the month.
                    </p>
                    <label className="flex items-start gap-2 text-sm text-foreground">
                      <input
                        type="checkbox"
                        checked={setupUpdatesOpenInvoices}
                        onChange={(event) => setSetupUpdatesOpenInvoices(event.target.checked)}
                        className="mt-0.5 h-4 w-4 rounded border-input accent-primary"
                      />
                      <span>Also move each selected client’s open invoice to the next occurrence of this monthly due day.</span>
                    </label>
                  </div>
                )}

                {clientSetupAction === 'installation_date' && (
                  <div className="mt-4 space-y-3">
                    <label className="block text-sm font-medium text-foreground">
                      Original installation date
                      <input
                        type="date"
                        value={setupInstallationDate}
                        onChange={(event) => setSetupInstallationDate(event.target.value)}
                        className="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                      />
                    </label>
                    <p className="rounded-md border border-blue-200 bg-blue-50 p-3 text-xs leading-5 text-blue-900 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-100">
                      This changes the historical installation record only. The client&apos;s real monthly billing due day remains unchanged.
                    </p>
                  </div>
                )}

                {clientSetupAction === 'address_updates' && (
                  <div className="mt-4 space-y-3">
                    <p className="rounded-md border border-blue-200 bg-blue-50 p-3 text-xs leading-5 text-blue-900 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-100">
                      Enter one address to apply to every selected client. This updates customer records only; it does not alter GPS coordinates, invoices, service status, or MikroTik settings.
                    </p>
                    <label className="block text-sm font-medium text-foreground">
                      New address for all selected clients
                      <input
                        value={setupSharedAddress}
                        onChange={(event) => setSetupSharedAddress(event.target.value)}
                        placeholder="Complete client address"
                        className="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-normal text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                      />
                    </label>
                    <p className="text-xs text-muted-foreground">{selectedSetupCustomers.length} selected client{selectedSetupCustomers.length === 1 ? '' : 's'} will receive this same address.</p>
                  </div>
                )}

                {clientSetupAction === 'previous_balance' && (
                  <div className="mt-4 space-y-3">
                    <label className="block text-sm font-medium text-foreground">
                      Previous bill balance per selected client
                      <div className="relative mt-1.5">
                        <span className="absolute left-3 top-2 text-sm text-muted-foreground">₱</span>
                        <input
                          type="number"
                          min="0.01"
                          step="0.01"
                          value={setupPreviousBalance}
                          onChange={(event) => setSetupPreviousBalance(event.target.value)}
                          placeholder="0.00"
                          className="w-full rounded-md border border-input bg-background py-2 pl-7 pr-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                        />
                      </div>
                    </label>
                    <label className="block text-sm font-medium text-foreground">
                      Previous bill due date
                      <input
                        type="date"
                        value={setupPreviousBalanceDueDate}
                        onChange={(event) => setSetupPreviousBalanceDueDate(event.target.value)}
                        className="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                      />
                    </label>
                    <p className="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
                      This creates one non-recurring opening-balance invoice for each selected billable client. It will not start automatic reminders, change plan prices, or duplicate a balance invoice that already exists.
                    </p>
                  </div>
                )}

                {clientSetupAction === 'discount' && (
                  <div className="mt-4 space-y-3">
                    <label className="block text-sm font-medium text-foreground">
                      Add this discount to every selected client’s open invoice
                      <div className="relative mt-1.5">
                        <span className="absolute left-3 top-2 text-sm text-muted-foreground">₱</span>
                        <input
                          type="number"
                          min="0.01"
                          step="0.01"
                          value={setupDiscount}
                          onChange={(event) => setSetupDiscount(event.target.value)}
                          placeholder="0.00"
                          className="w-full rounded-md border border-input bg-background py-2 pl-7 pr-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                        />
                      </div>
                    </label>
                    <p className="rounded-md border border-blue-200 bg-blue-50 p-3 text-xs leading-5 text-blue-900 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-100">
                      The same peso amount is added to each selected client’s existing unpaid invoice. No invoice is created and the service-plan price is not changed. Paid invoices are never touched.
                    </p>
                  </div>
                )}

                {clientSetupAction === 'status' && (
                  <div className="mt-4 space-y-3">
                    <label className="block text-sm font-medium text-foreground">
                      Account status
                      <select
                        value={setupStatus}
                        onChange={(event) => setSetupStatus(event.target.value as typeof setupStatus)}
                        className="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                      >
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                        <option value="expired">Expired</option>
                        <option value="pending">Pending</option>
                      </select>
                    </label>
                    <p className="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
                      This bulk setup updates the account record only. It does not send a MikroTik queue, firewall, DHCP, or connection change. Use the normal suspend or restore action when a reviewed network change is needed.
                    </p>
                  </div>
                )}

                {clientSetupAction === 'delete' && (
                  <p className="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-xs leading-5 text-red-900 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-100">
                    Deletes (soft-archives) every selected customer record. Use this only for accidental imports or test clients. This action requires the separate delete-customers permission.
                  </p>
                )}
              </section>
            </div>

            <div className="sticky bottom-0 flex flex-col-reverse gap-2 border-t border-border bg-card px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
              <p className="text-xs text-muted-foreground">{selectedIds.size} client{selectedIds.size === 1 ? '' : 's'} will be affected.</p>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setClientSetupOpen(false)}
                  disabled={setupSubmitting}
                  className="rounded-md bg-secondary px-4 py-2 text-sm text-secondary-foreground hover:opacity-90 disabled:opacity-50"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={handleClientSetup}
                  disabled={setupSubmitting || selectedIds.size === 0}
                  className={`rounded-md px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50 ${clientSetupAction === 'delete' ? 'bg-red-600 hover:bg-red-700' : 'bg-primary hover:opacity-90'}`}
                  data-testid="client-setups-apply"
                >
                  {setupSubmitting ? 'Applying…' : clientSetupAction === 'delete' ? `Delete ${selectedIds.size} selected` : 'Apply client setup'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Delete confirmation modal */}
      {deleteTarget && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
          data-testid="delete-customer-modal"
        >
          <div className="bg-card border border-border rounded-lg shadow-xl max-w-md w-full p-6">
            <h3 className="text-lg font-bold text-foreground mb-2">Delete customer?</h3>
            <p className="text-sm text-muted-foreground mb-4">
              You are about to delete <strong className="text-foreground">{deleteTarget.full_name}</strong> ({deleteTarget.account_number}).
              This will archive the customer (soft delete) and remove their MikroTik queue on next sync.
            </p>
            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={() => setDeleteTarget(null)}
                disabled={deleting}
                className="px-4 py-2 bg-secondary text-secondary-foreground rounded-md hover:opacity-90 disabled:opacity-50"
                data-testid="delete-cancel-btn"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleConfirmDelete}
                disabled={deleting}
                className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50"
                data-testid="delete-confirm-btn"
              >
                {deleting ? 'Deleting…' : 'Yes, delete'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Bulk delete confirmation modal */}
      {bulkDeleteOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
          data-testid="bulk-delete-modal"
        >
          <div className="bg-card border border-border rounded-lg shadow-xl max-w-md w-full p-6">
            <h3 className="text-lg font-bold text-foreground mb-2">Delete {selectedIds.size} customer{selectedIds.size === 1 ? '' : 's'}?</h3>
            <p className="text-sm text-muted-foreground mb-4">
              This will archive <strong className="text-foreground">{selectedIds.size}</strong> customer{selectedIds.size === 1 ? '' : 's'} (soft delete) and remove their MikroTik queues on next sync. This action cannot be quickly undone from the UI.
            </p>
            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={() => setBulkDeleteOpen(false)}
                disabled={deleting}
                className="px-4 py-2 bg-secondary text-secondary-foreground rounded-md hover:opacity-90 disabled:opacity-50"
                data-testid="bulk-delete-cancel-btn"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleBulkDelete}
                disabled={deleting}
                className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50"
                data-testid="bulk-delete-confirm-btn"
              >
                {deleting ? 'Deleting…' : `Yes, delete ${selectedIds.size}`}
              </button>
            </div>
          </div>
        </div>
      )}
    </DashboardLayout>
  );
};

export default CustomersPage;
