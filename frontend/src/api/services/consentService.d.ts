export interface ConsentCacheEntry {
  consented: boolean;
  version: string;
  timestamp: number;
}

export const getCachedConsent: (userId: number | string) => ConsentCacheEntry | null;

export const saveCachedConsent: (userId: number | string, version: string) => void;

export const clearCachedConsent: (userId: number | string) => void;

export const getConsentStatus: () => Promise<{
  success: boolean;
  consented: boolean;
  consent_version: string;
  message?: string;
}>;

export const verifyEmployeeId: (nationalId: string) => Promise<{
  success: boolean;
  message: string;
  data?: {
    employee_id: string;
    employee_name: string;
  };
}>;

export const submitConsent: (employeeId: string) => Promise<{
  success: boolean;
  message: string;
  data?: {
    consent_version: string;
    consented: boolean;
    already_consented?: boolean;
  };
}>;

export const getConsentDashboard: () => Promise<{
  success: boolean;
  data: {
    total_employees: number;
    consented: number;
    pending: number;
    declined: number;
    consent_rate: number;
  };
}>;

export const getEmployeeConsentList: (filters?: {
  status?: string;
  department_id?: number | string;
  section_id?: number | string;
  search?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}) => Promise<{
  success: boolean;
  data: {
    employees: Array<{
      employee_id: string;
      first_name: string;
      last_name: string;
      email: string;
      gender: string;
      department: string;
      section: string;
      consent_status: string;
      consent_version: string;
      consent_date: string | null;
    }>;
    pagination: {
      page: number;
      per_page: number;
      total: number;
      total_pages: number;
    };
    departments: Array<{
      id: number;
      name: string;
    }>;
    sections: Array<{
      id: number;
      name: string;
      department_id: number;
    }>;
    versions: string[];
  };
}>;