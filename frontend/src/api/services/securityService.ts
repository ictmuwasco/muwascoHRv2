import apiClient from '../client';

const SECURITY_API = '/security';

export const securityService = {
  getOverview: () => apiClient.get(`${SECURITY_API}/overview`),
  getEvents: (params = {}) => apiClient.get(`${SECURITY_API}/events`, { params }),
  getEvent: (id: number) => apiClient.get(`${SECURITY_API}/events/${id}`),
  getIncidents: (params = {}) => apiClient.get(`${SECURITY_API}/incidents`, { params }),
  getIncident: (id: number) => apiClient.get(`${SECURITY_API}/incidents/${id}`),
  getThreats: () => apiClient.get(`${SECURITY_API}/threats`),
  getPosture: () => apiClient.get(`${SECURITY_API}/posture`),
  getEndpoints: () => apiClient.get(`${SECURITY_API}/endpoints`),
  getVulnerabilities: () => apiClient.get(`${SECURITY_API}/vulnerabilities`),
  getUserActivity: (userId: number) => apiClient.get(`${SECURITY_API}/activity/${userId}`),
  analyzeIncident: (incidentId: number | null, eventIds: number[] = []) =>
    apiClient.post(`${SECURITY_API}/ai/analyze`, { incident_id: incidentId, event_ids: eventIds }),
  getAiThreats: () => apiClient.get(`${SECURITY_API}/ai/threats`),
  copilot: (question: string, tools: string[] = []) =>
    apiClient.post(`${SECURITY_API}/ai/copilot`, { question, tools }),
  resolveIncident: (id: number, notes: string) =>
    apiClient.post(`${SECURITY_API}/incidents/${id}/resolve`, { notes }),
  falsePositive: (id: number, notes: string) =>
    apiClient.post(`${SECURITY_API}/incidents/${id}/false-positive`, { notes }),
  investigateIncident: (id: number) =>
    apiClient.post(`${SECURITY_API}/incidents/${id}/investigate`),
  containIncident: (id: number) =>
    apiClient.post(`${SECURITY_API}/incidents/${id}/contain`),
};
