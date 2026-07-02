import React, { useState, useEffect } from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { BarChart3, TrendingUp, Users, DollarSign, Calendar } from 'lucide-react';
import reportService from '../services/reportService';
import { formatPHP } from '../lib/currency';

const ReportsPage: React.FC = () => {
  const [loading, setLoading] = useState(true);
  const [dateRange, setDateRange] = useState({
    start: new Date(new Date().setMonth(new Date().getMonth() - 1)).toISOString().split('T')[0],
    end: new Date().toISOString().split('T')[0],
  });
  const [revenueData, setRevenueData] = useState<any>(null);
  const [customerGrowth, setCustomerGrowth] = useState<any>(null);

  useEffect(() => {
    fetchReports();
  }, [dateRange]);

  const fetchReports = async () => {
    try {
      setLoading(true);
      const [revenue, growth] = await Promise.all([
        reportService.getRevenueReport(dateRange.start, dateRange.end),
        reportService.getCustomerGrowth(dateRange.start, dateRange.end),
      ]);
      setRevenueData(revenue);
      setCustomerGrowth(growth);
    } catch (error) {
      console.error('Error fetching reports:', error);
    } finally {
      setLoading(false);
    }
  };

  return (
    <DashboardLayout>
      <div className="p-6">
        <div className="mb-6 flex justify-between items-center">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Reports & Analytics</h1>
            <p className="text-sm text-gray-600 mt-1">Business insights and metrics</p>
          </div>
          <div className="flex gap-3">
            <input
              type="date"
              value={dateRange.start}
              onChange={(e) => setDateRange({ ...dateRange, start: e.target.value })}
              className="px-3 py-2 border border-gray-300 rounded-lg text-sm"
            />
            <input
              type="date"
              value={dateRange.end}
              onChange={(e) => setDateRange({ ...dateRange, end: e.target.value })}
              className="px-3 py-2 border border-gray-300 rounded-lg text-sm"
            />
          </div>
        </div>

        {loading ? (
          <div className="flex items-center justify-center h-64">
            <div className="text-center">
              <div className="w-16 h-16 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto mb-4" />
              <p className="text-gray-600">Loading reports...</p>
            </div>
          </div>
        ) : (
          <div className="space-y-6">
            {/* Revenue Overview */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
              <div className="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl p-6 text-white shadow-lg">
                <div className="flex items-center justify-between mb-4">
                  <DollarSign className="w-8 h-8 opacity-80" />
                  <span className="text-sm font-medium opacity-90">Total Revenue</span>
                </div>
                <p className="text-3xl font-bold">{formatPHP(revenueData?.total_revenue)}</p>
                <p className="text-sm opacity-80 mt-2">Last 30 days</p>
              </div>

              <div className="bg-gradient-to-br from-green-500 to-green-600 rounded-xl p-6 text-white shadow-lg">
                <div className="flex items-center justify-between mb-4">
                  <BarChart3 className="w-8 h-8 opacity-80" />
                  <span className="text-sm font-medium opacity-90">Invoices</span>
                </div>
                <p className="text-3xl font-bold">{revenueData?.invoice_count || 0}</p>
                <p className="text-sm opacity-80 mt-2">Generated</p>
              </div>

              <div className="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl p-6 text-white shadow-lg">
                <div className="flex items-center justify-between mb-4">
                  <TrendingUp className="w-8 h-8 opacity-80" />
                  <span className="text-sm font-medium opacity-90">Avg Invoice</span>
                </div>
                <p className="text-3xl font-bold">{formatPHP(revenueData?.average_invoice)}</p>
                <p className="text-sm opacity-80 mt-2">Per customer</p>
              </div>

              <div className="bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl p-6 text-white shadow-lg">
                <div className="flex items-center justify-between mb-4">
                  <Users className="w-8 h-8 opacity-80" />
                  <span className="text-sm font-medium opacity-90">Active Customers</span>
                </div>
                <p className="text-3xl font-bold">{customerGrowth?.stats?.active || 0}</p>
                <p className="text-sm opacity-80 mt-2">Current total</p>
              </div>
            </div>

            {/* Revenue Chart Placeholder */}
            <div className="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
              <h3 className="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                <BarChart3 className="w-5 h-5 text-blue-600" />
                Revenue Trend
              </h3>
              <div className="h-64 flex items-center justify-center bg-gray-50 rounded-lg">
                <p className="text-gray-500">Chart visualization (Install recharts for interactive charts)</p>
              </div>
            </div>

            {/* Customer Growth */}
            <div className="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
              <h3 className="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                <Users className="w-5 h-5 text-green-600" />
                Customer Statistics
              </h3>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div className="p-4 bg-blue-50 rounded-lg">
                  <p className="text-sm text-gray-600">Total</p>
                  <p className="text-2xl font-bold text-blue-600">{customerGrowth?.stats?.total || 0}</p>
                </div>
                <div className="p-4 bg-green-50 rounded-lg">
                  <p className="text-sm text-gray-600">Active</p>
                  <p className="text-2xl font-bold text-green-600">{customerGrowth?.stats?.active || 0}</p>
                </div>
                <div className="p-4 bg-yellow-50 rounded-lg">
                  <p className="text-sm text-gray-600">Suspended</p>
                  <p className="text-2xl font-bold text-yellow-600">{customerGrowth?.stats?.suspended || 0}</p>
                </div>
                <div className="p-4 bg-purple-50 rounded-lg">
                  <p className="text-sm text-gray-600">New This Month</p>
                  <p className="text-2xl font-bold text-purple-600">{customerGrowth?.stats?.new_this_month || 0}</p>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </DashboardLayout>
  );
};

export default ReportsPage;
