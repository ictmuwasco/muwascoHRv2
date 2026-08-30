import React, { useState } from 'react';
import Button from '../ui/Button';

const CreateFinancialYearCard = ({ canCreate, nextFY, onCreate, creating }) => {
  const [showConfirmation, setShowConfirmation] = useState(false);

  if (!canCreate || !nextFY) {
    return (
      <div className="bg-white dark:bg-slate-800 rounded-xl border border-primary-600 shadow-md shadow-primary-600/40 p-6 mb-6">
        <h3 className="text-lg font-semibold mb-4">Add New Financial Year</h3>
        <div className="bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-400 dark:border-yellow-700 rounded-lg p-4">
          <p className="text-sm text-yellow-800 dark:text-yellow-200">
            <strong>Note:</strong> {nextFY?.reason || 'Cannot create financial year at this time.'}
          </p>
        </div>
      </div>
    );
  }

  const handleConfirm = () => {
    setShowConfirmation(false);
    onCreate(nextFY);
  };

  return (
    <>
      <div className="bg-white dark:bg-slate-800 rounded-xl border border-primary-600 shadow-md shadow-primary-600/40 p-6 mb-6">
        <h3 className="text-lg font-semibold mb-4">Add New Financial Year</h3>
        
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Start Date
            </label>
            <input
              type="text"
              className="w-full px-3 py-2 bg-gray-100 dark:bg-slate-900 border border-gray-300 dark:border-slate-600 rounded-md text-sm text-gray-900 dark:text-gray-100"
              value={nextFY.start_date}
              readOnly
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              End Date
            </label>
            <input
              type="text"
              className="w-full px-3 py-2 bg-gray-100 dark:bg-slate-900 border border-gray-300 dark:border-slate-600 rounded-md text-sm text-gray-900 dark:text-gray-100"
              value={nextFY.end_date}
              readOnly
            />
          </div>
        </div>

        <div className="mb-4">
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            Financial Year Details
          </label>
          <input
            type="text"
            className="w-full px-3 py-2 bg-gray-100 dark:bg-slate-900 border border-gray-300 dark:border-slate-600 rounded-md text-sm text-gray-900 dark:text-gray-100"
            value={
              nextFY.total_days
                ? `${nextFY.year_name} (${nextFY.total_days} days)`
                : 'Not available'
            }
            readOnly
          />
        </div>

        <div className="flex items-center space-x-2">
          <Button
            onClick={() => setShowConfirmation(true)}
            disabled={creating}
            loading={creating}
          >
            Create Financial Year {nextFY.year_name}
          </Button>
        </div>
      </div>

      {showConfirmation && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-slate-800 rounded-lg p-6 max-w-md w-full mx-4">
            <h3 className="text-lg font-semibold mb-4">Confirm Creation</h3>
            <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">
              Are you sure you want to create financial year <strong>{nextFY.year_name}</strong>?
              This will:
            </p>
            <ul className="list-disc list-inside text-sm text-gray-600 dark:text-gray-300 mb-6 space-y-1">
              <li>Create the financial year</li>
              <li>Allocate applicable leave to all employees</li>
              <li>Process existing employee records</li>
              <li>Trigger notifications</li>
            </ul>
            {creating && (
              <div className="mb-4 flex items-center justify-center py-4">
                <svg
                  className="animate-spin h-8 w-8 text-primary-600"
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                >
                  <circle
                    className="opacity-25"
                    cx="12"
                    cy="12"
                    r="10"
                    stroke="currentColor"
                    strokeWidth="4"
                  />
                  <path
                    className="opacity-75"
                    fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                  />
                </svg>
                <span className="ml-3 text-sm text-gray-600 dark:text-gray-300">
                  Creating financial year and allocating leave to employees...
                </span>
              </div>
            )}
            <div className="flex items-center space-x-2 justify-end">
              <Button
                variant="outline"
                onClick={() => setShowConfirmation(false)}
                disabled={creating}
              >
                Cancel
              </Button>
              <Button onClick={handleConfirm} loading={creating}>
                {creating ? 'Creating...' : 'Create Financial Year'}
              </Button>
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default CreateFinancialYearCard;