import React from 'react';

const FinancialYearStatusCard = ({ status }) => {
  if (!status) return null;

  const alertClass = {
    success: 'bg-green-50 border-green-400 text-green-800',
    danger: 'bg-red-50 border-red-400 text-red-800',
    warning: 'bg-yellow-50 border-yellow-400 text-yellow-800',
    info: 'bg-blue-50 border-blue-400 text-blue-800',
  }[status.alert_class] || 'bg-gray-50 border-gray-400 text-gray-800';

  return (
    <div className={`rounded-lg border p-4 mb-6 ${alertClass}`}>
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
                className="w-full px-3 py-2 bg-white bg-opacity-50 border border-current rounded-md text-sm"
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
                className="w-full px-3 py-2 bg-white bg-opacity-50 border border-current rounded-md text-sm"
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
                className="w-full px-3 py-2 bg-white bg-opacity-50 border border-current rounded-md text-sm"
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