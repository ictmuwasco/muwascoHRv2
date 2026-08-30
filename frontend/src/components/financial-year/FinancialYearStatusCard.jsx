import React from 'react';

const FinancialYearStatusCard = ({ status }) => {
  if (!status) return null;

  const alertClass = {
    success: 'bg-green-50 dark:bg-green-900/30 border-green-400 dark:border-green-700 text-green-800 dark:text-green-200',
    danger: 'bg-red-50 dark:bg-red-900/30 border-red-400 dark:border-red-700 text-red-800 dark:text-red-200',
    warning: 'bg-yellow-50 dark:bg-yellow-900/30 border-yellow-400 dark:border-yellow-700 text-yellow-800 dark:text-yellow-200',
    info: 'bg-blue-50 dark:bg-blue-900/30 border-blue-400 dark:border-blue-700 text-blue-800 dark:text-blue-200',
  }[status.alert_class] || 'bg-gray-50 dark:bg-slate-700 border-gray-400 dark:border-slate-500 text-gray-800 dark:text-gray-100';

  return (
    <div className={`rounded-xl border border-primary-600 shadow-md shadow-primary-600/40 p-4 mb-6 ${alertClass}`}>
      <div className="flex items-start justify-between">
        <div className="flex-1">
          <h3 className="text-lg font-semibold mb-2">Financial Year Status</h3>
          <p className="text-sm mb-4">{status.message}</p>
          
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            <div>
              <label className="block text-xs font-medium opacity-75 mb-1">
                Current Month
              </label>
              <input
                type="text"
                className="w-full px-3 py-2 bg-white bg-opacity-50 dark:bg-slate-900/40 border border-current rounded-md text-sm text-gray-900 dark:text-gray-100"
                value={status.current_month}
                readOnly
              />
            </div>
            <div>
              <label className="block text-xs font-medium opacity-75 mb-1">
                Current Date
              </label>
              <input
                type="text"
                className="w-full px-3 py-2 bg-white bg-opacity-50 dark:bg-slate-900/40 border border-current rounded-md text-sm text-gray-900 dark:text-gray-100"
                value={status.current_date}
                readOnly
              />
            </div>
            <div>
              <label className="block text-xs font-medium opacity-75 mb-1">
                Next Financial Year
              </label>
              <input
                type="text"
                className="w-full px-3 py-2 bg-white bg-opacity-50 dark:bg-slate-900/40 border border-current rounded-md text-sm text-gray-900 dark:text-gray-100"
                value={
                  status.next_financial_year
                    ? `${status.next_financial_year.year_name} (${status.next_financial_year.start_date} to ${status.next_financial_year.end_date})`
                    : 'Not available'
                }
                readOnly
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default FinancialYearStatusCard;