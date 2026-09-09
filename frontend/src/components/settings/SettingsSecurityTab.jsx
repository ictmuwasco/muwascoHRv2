import { useState, useEffect, useCallback } from 'react';
import Card from '../ui/Card';
import { securityService } from '../../api/services/securityService';
import { Shield, AlertTriangle, AlertOctagon, Activity, CheckCircle, RefreshCw, Bot, MessageCircle, Send } from 'lucide-react';

const SecurityTab = () => {
  const [overview, setOverview] = useState(null);
  const [events, setEvents] = useState([]);
  const [eventsMeta, setEventsMeta] = useState({ page: 1, per_page: 25, total: 0 });
  const [incidents, setIncidents] = useState([]);
  const [incidentsMeta, setIncidentsMeta] = useState({ page: 1, per_page: 25, total: 0 });
  const [aiThreats, setAiThreats] = useState(null);
  const [vulnerabilities, setVulnerabilities] = useState([]);
  const [endpoints, setEndpoints] = useState([]);
  const [selectedTab, setSelectedTab] = useState('overview');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [copilotMessages, setCopilotMessages] = useState([]);
  const [copilotInput, setCopilotInput] = useState('');
  const [copilotLoading, setCopilotLoading] = useState(false);
  const [aiThreatsLoading, setAiThreatsLoading] = useState(false);

  const TABS = ['overview', 'events', 'incidents', 'endpoints', 'vulnerabilities', 'ai-analyst', 'ai-copilot'];

  const fetchData = useCallback(async () => {
    try {
      setLoading(true);
      const [oRes, eRes, iRes] = await Promise.all([
        securityService.getOverview(),
        securityService.getEvents({ per_page: 10 }),
        securityService.getIncidents({ per_page: 10 }),
      ]);
      setOverview(oRes.data?.data || oRes.data);
      setEvents(eRes.data?.data?.data || []);
      setEventsMeta({
        page: eRes.data?.data?.page || 1,
        per_page: eRes.data?.data?.per_page || 25,
        total: eRes.data?.data?.total || 0,
      });
      setIncidents(iRes.data?.data?.data || []);
      setIncidentsMeta({
        page: iRes.data?.data?.page || 1,
        per_page: iRes.data?.data?.per_page || 25,
        total: iRes.data?.data?.total || 0,
      });
    } catch (err) { setError('Failed to load security data'); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { fetchData(); }, [fetchData]);

  const loadAiThreats = useCallback(async () => {
    try {
      setAiThreatsLoading(true);
      const res = await securityService.getAiThreats();
      setAiThreats(res.data?.data || res.data);
    } catch (err) {
      setAiThreats({ error: 'Failed to load AI analysis', threats: [] });
    } finally { setAiThreatsLoading(false); }
  }, []);

  const loadEndpoints = useCallback(async () => {
    try {
      const res = await securityService.getEndpoints();
      const rows = res.data?.data || res.data;
      setEndpoints(Array.isArray(rows) ? rows : []);
    } catch (err) { setEndpoints([]); }
  }, []);

  const loadVulnerabilities = useCallback(async () => {
    try {
      const res = await securityService.getVulnerabilities();
      setVulnerabilities(res.data?.data?.data || []);
    } catch (err) { /* silently fail */ }
  }, []);

  const handleCopilotSend = async () => {
    if (!copilotInput.trim()) return;
    const userMsg = { role: 'user', content: copilotInput, timestamp: new Date().toISOString() };
    setCopilotMessages(prev => [...prev, userMsg]);
    setCopilotInput('');
    setCopilotLoading(true);
    try {
      const res = await securityService.copilot(copilotInput, [
        'get_security_events', 'get_security_incident',
        'get_user_security_activity', 'get_endpoint_security_activity',
        'get_authentication_activity',
      ]);
      const reply = res.data?.data?.reply || 'No response received.';
      const aiMsg = { role: 'assistant', content: reply, timestamp: new Date().toISOString() };
      setCopilotMessages(prev => [...prev, aiMsg]);
    } catch (err) {
      const aiMsg = { role: 'assistant', content: 'I could not process that request. Please try again.', timestamp: new Date().toISOString() };
      setCopilotMessages(prev => [...prev, aiMsg]);
    } finally { setCopilotLoading(false); }
  };

  const postureColor = (p) => {
    switch (String(p || '')) {
      case 'CRITICAL': return 'bg-red-100 text-red-800 border-red-200';
      case 'HIGH_RISK': return 'bg-orange-100 text-orange-800 border-orange-200';
      case 'WARNING': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
      default: return 'bg-green-100 text-green-800 border-green-200';
    }
  };

  const sevBadge = (s) => {
    const c = { LOW: 'bg-blue-100 text-blue-800', MEDIUM: 'bg-yellow-100 text-yellow-800', HIGH: 'bg-orange-100 text-orange-800', CRITICAL: 'bg-red-100 text-red-800' };
    return c[s] || 'bg-gray-100 text-gray-800';
  };

  const fmtDate = (value) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? '-' : d.toLocaleString();
  };

  const fmtType = (value) => String(value || 'Unknown event').replace(/_/g, ' ');

  const fmtAction = (value) => String(value || 'unknown').replace(/_/g, ' ');

  const fmtClass = (value) => String(value || 'Unclassified').replace(/_/g, ' ');

  const statusColor = (s) => {
    const c = { NEW: 'bg-blue-100 text-blue-800', INVESTIGATING: 'bg-yellow-100 text-yellow-800', CONTAINED: 'bg-orange-100 text-orange-800', RESOLVED: 'bg-green-100 text-green-800', FALSE_POSITIVE: 'bg-gray-100 text-gray-800' };
    return c[s] || 'bg-gray-100 text-gray-800';
  };

  const vulnStatusColor = (s) => {
    const c = {
      OPEN: 'bg-blue-100 text-blue-800',
      ACKNOWLEDGED: 'bg-indigo-100 text-indigo-800',
      IN_PROGRESS: 'bg-yellow-100 text-yellow-800',
      MITIGATED: 'bg-orange-100 text-orange-800',
      RESOLVED: 'bg-green-100 text-green-800',
      FALSE_POSITIVE: 'bg-gray-100 text-gray-800',
      ACCEPTED_RISK: 'bg-purple-100 text-purple-800',
      REOPENED: 'bg-red-100 text-red-800',
    };
    return c[s] || 'bg-gray-100 text-gray-800';
  };

  if (loading) return <div className="flex items-center justify-center h-64"><div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div></div>;

  return (
    <div className="space-y-6">
      <div className="flex space-x-1 bg-gray-100 dark:bg-slate-800 rounded-lg p-1 overflow-x-auto">
        {TABS.map((tab) => (
          <button key={tab} onClick={() => { setSelectedTab(tab); if (tab === 'ai-analyst') loadAiThreats(); if (tab === 'vulnerabilities') loadVulnerabilities(); if (tab === 'endpoints') loadEndpoints(); }}
            className={`px-4 py-2 rounded-md text-sm font-medium whitespace-nowrap transition-colors ${selectedTab === tab ? 'bg-white dark:bg-slate-700 text-primary-600 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'}`}>
            {tab.replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
          </button>
        ))}
      </div>
      {error && <div className="p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">{error}</div>}
      {selectedTab === 'overview' && overview && (
        <div className="space-y-6">
          <Card>
            <div className="flex items-center justify-between">
              <div><h3 className="text-lg font-semibold">Security Posture</h3><p className="text-sm text-gray-500 mt-1">{overview.posture?.reasons?.join(', ') || 'All systems nominal'}</p></div>
              <div className={`px-4 py-2 rounded-lg border font-bold text-lg ${postureColor(overview.posture?.posture)}`}>{overview.posture?.posture?.replace('_', ' ') || 'GOOD'}</div>
            </div>
          </Card>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <Card><div className="flex items-center space-x-3"><div className="p-2 bg-blue-100 rounded-lg"><Activity className="h-5 w-5 text-blue-600" /></div><div><p className="text-2xl font-bold">{overview.events_today}</p><p className="text-xs text-gray-500">Events Today</p></div></div></Card>
            <Card><div className="flex items-center space-x-3"><div className="p-2 bg-red-100 rounded-lg"><AlertOctagon className="h-5 w-5 text-red-600" /></div><div><p className="text-2xl font-bold">{overview.critical_events}</p><p className="text-xs text-gray-500">Critical Events</p></div></div></Card>
            <Card><div className="flex items-center space-x-3"><div className="p-2 bg-orange-100 rounded-lg"><AlertTriangle className="h-5 w-5 text-orange-600" /></div><div><p className="text-2xl font-bold">{overview.active_incidents}</p><p className="text-xs text-gray-500">Active Incidents</p></div></div></Card>
            <Card><div className="flex items-center space-x-3"><div className="p-2 bg-purple-100 rounded-lg"><Shield className="h-5 w-5 text-purple-600" /></div><div><p className="text-2xl font-bold">{overview.critical_incidents}</p><p className="text-xs text-gray-500">Critical Incidents</p></div></div></Card>
          </div>
          <Card title="Recent Security Events">
            {events.length > 0 ? events.slice(0, 5).map((e) => (
              <div key={e.id} className="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-800 rounded-lg">
                <div className="flex items-center space-x-3"><span className={`px-2 py-1 rounded text-xs font-medium ${sevBadge(e.severity)}`}>{e.severity}</span><div><p className="text-sm font-medium">{e.event_type.replace(/_/g, ' ')}</p><p className="text-xs text-gray-500">{e.route || 'N/A'}</p></div></div>
                <span className="text-xs text-gray-400">{new Date(e.detected_at).toLocaleTimeString()}</span>
              </div>
            )) : <p className="text-gray-500 text-center py-4">No recent security events</p>}
          </Card>
        </div>
      )}
      {selectedTab === 'events' && (
        <Card title="Security Events" subtitle={`${eventsMeta.total} events`}>
          {events.length > 0 ? events.map((e) => (
            <div key={e.id} className="flex items-center justify-between p-3 border-b dark:border-slate-700">
              <div className="flex items-center space-x-3">
                <span className={`px-2 py-0.5 rounded text-xs font-medium ${sevBadge(e.severity)}`}>{e.severity}</span>
                <span className="text-sm font-medium">{fmtType(e.event_type)}</span>
              </div>
              <div className="flex items-center space-x-4 text-xs">
                <span className="text-gray-500">{e.user_id ? 'User #' + e.user_id : 'System'}</span>
                <span className="text-gray-500">{e.ip_address}</span>
                <span className="text-gray-500">{e.route}</span>
                <span className={`px-2 py-0.5 rounded ${e.action_taken === 'BLOCKED' || e.action_taken === 'DENIED' ? 'bg-red-100 text-red-800' : e.action_taken === 'RATE_LIMITED' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-700'}`}>{fmtAction(e.action_taken)}</span>
                <span className="text-gray-400">{fmtDate(e.detected_at)}</span>
              </div>
            </div>
          )) : <p className="text-gray-500 text-center py-8">No events</p>}
        </Card>
      )}
      {selectedTab === 'incidents' && (
        <Card title="Security Incidents" subtitle={`${incidentsMeta.total} incidents`}>
          {incidents.length > 0 ? incidents.map((inc) => (
            <div key={inc.id} className="flex items-center justify-between p-3 border-b dark:border-slate-700">
              <div className="flex items-center space-x-3">
                <span className={`px-2 py-0.5 rounded text-xs font-medium ${sevBadge(inc.severity)}`}>{inc.severity}</span>
                <span className={`px-2 py-0.5 rounded text-xs font-medium ${statusColor(inc.status)}`}>{inc.status}</span>
              </div>
              <div className="flex-1 ml-4"><p className="text-sm font-medium">{inc.summary || inc.ai_reasoning?.substring(0, 100) || 'Security incident'}</p></div>
              <div className="flex items-center space-x-2 text-xs">
                {inc.ai_classification && <span className="px-2 py-0.5 rounded bg-slate-100 text-slate-700">{fmtClass(inc.ai_classification)}</span>}
                <span className="text-gray-500">Risk: {inc.risk_score}</span>
                <span className="text-gray-400">{fmtDate(inc.last_seen)}</span>
              </div>
            </div>
          )) : <p className="text-gray-500 text-center py-8">No incidents</p>}
        </Card>
      )}
      {selectedTab === 'endpoints' && (
        <Card title="Endpoint Security Matrix">
          <div className="overflow-x-auto"><table className="w-full text-sm">
            <thead><tr className="text-left border-b dark:border-slate-700"><th className="pb-2">Method</th><th className="pb-2">Route</th><th className="pb-2">Permission</th><th className="pb-2">Object Auth</th><th className="pb-2">Rate Limit</th><th className="pb-2">Monitoring</th></tr></thead>
            <tbody>
              {endpoints.length > 0 ? endpoints.map((ep, i) => (
                <tr key={i} className="border-b dark:border-slate-800">
                  <td className="py-2"><span className={`px-2 py-0.5 rounded text-xs font-bold ${ep.method === 'GET' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800'}`}>{ep.method}</span></td>
                  <td className="py-2 font-mono text-xs">{ep.route}</td>
                  <td className="py-2">{ep.permission || '-'}</td>
                  <td className="py-2">{ep.object_auth ? 'Yes' : 'No'}</td>
                  <td className="py-2">{ep.rate_limit ? 'Yes' : 'No'}</td>
                  <td className="py-2">{ep.monitoring ? 'Yes' : 'No'}</td>
                </tr>
              )) : (
                <tr><td colSpan={6} className="py-4 text-center text-gray-500">Endpoint inventory unavailable</td></tr>
              )}
            </tbody>
          </table></div>
        </Card>
      )}
      {selectedTab === 'vulnerabilities' && (
        <Card title="Vulnerabilities" subtitle={`${vulnerabilities.length} records`}>
          <div className="space-y-3">
            {vulnerabilities.length > 0 ? vulnerabilities.map((v, i) => (
              <div key={v.id || i} className="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-800 rounded-lg">
                <div className="flex items-center space-x-3">
                  {v.status === 'RESOLVED' || v.status === 'FALSE_POSITIVE' ? (
                    <CheckCircle className="h-5 w-5 text-green-500" />
                  ) : v.severity === 'CRITICAL' || v.severity === 'HIGH' ? (
                    <AlertOctagon className="h-5 w-5 text-red-500" />
                  ) : (
                    <AlertTriangle className="h-5 w-5 text-yellow-500" />
                  )}
                  <div>
                    <p className="font-medium">{v.title}</p>
                    <p className="text-xs text-gray-500">{v.affected_endpoint || v.category || 'Unknown'}</p>
                  </div>
                </div>
                <div className="flex items-center space-x-2">
                  <span className={`px-2 py-0.5 rounded text-xs font-medium ${sevBadge(v.severity)}`}>{v.severity}</span>
                  <span className={`px-2 py-0.5 rounded text-xs font-medium ${vulnStatusColor(v.status)}`}>{v.status?.replace(/_/g, ' ')}</span>
                  {v.risk_score != null && <span className="text-xs text-gray-500">Risk: {v.risk_score}</span>}
                  <span className="text-xs text-gray-400">{fmtDate(v.last_seen_at || v.first_detected_at)}</span>
                </div>
              </div>
            )) : (
              <p className="text-gray-500 text-center py-8">No vulnerabilities recorded</p>
            )}
          </div>
        </Card>
      )}
      {selectedTab === 'ai-analyst' && (
        <div className="space-y-6">
          <Card>
            <div className="flex items-center space-x-3"><Bot className="h-6 w-6 text-primary-600" /><h3 className="text-lg font-semibold">AI Security Analyst</h3></div>
            <p className="text-sm text-gray-500 mt-1">NVIDIA AI analysis of recent security events</p>
          </Card>
          {aiThreatsLoading ? (
            <div className="flex items-center justify-center h-40"><div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600"></div></div>
          ) : aiThreats?.error ? (
            <div className="p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">{aiThreats.error}</div>
          ) : (
            <div className="space-y-4">
              {(aiThreats?.threats || []).map((t, i) => (
                <Card key={i}>
                  <div className="flex items-start justify-between">
                    <div className="flex items-start space-x-3">
                      <AlertOctagon className={`h-5 w-5 mt-0.5 ${t.confidence > 0.8 ? 'text-red-500' : t.confidence > 0.5 ? 'text-orange-500' : 'text-yellow-500'}`} />
                      <div>
                        <p className="font-medium">{t.classification?.replace(/_/g, ' ')}</p>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">{t.reasoning_summary || 'No reasoning provided.'}</p>
                        <div className="flex items-center space-x-4 mt-2 text-xs">
                          <span className="text-gray-500">Confidence: {Math.round((t.confidence || 0) * 100)}%</span>
                          <span className="text-gray-500">Risk: {t.risk_score_recommendation || 0}/100</span>
                          <span className="text-gray-500">Action: {t.recommended_action?.replace(/_/g, ' ')}</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </Card>
              ))}
              {(!aiThreats?.threats || aiThreats.threats.length === 0) && !aiThreats?.error && (
                <p className="text-gray-500 text-center py-8">No threats detected in recent events</p>
              )}
            </div>
          )}
          <button onClick={loadAiThreats} disabled={aiThreatsLoading} className="flex items-center space-x-2 px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-50">
            <RefreshCw className="h-4 w-4" /><span>Refresh AI Analysis</span>
          </button>
        </div>
      )}
      {selectedTab === 'ai-copilot' && (
        <div className="flex flex-col h-[500px]">
          <div className="flex items-center space-x-3 mb-4"><Bot className="h-6 w-6 text-primary-600" /><h3 className="text-lg font-semibold">AI Security Copilot</h3></div>
          <p className="text-sm text-gray-500 mb-4">Ask questions about security activity. Uses controlled backend tools only.</p>
          <div className="flex-1 overflow-y-auto space-y-4 mb-4">
            {copilotMessages.length === 0 ? (
              <div className="text-center py-8 text-gray-500">
                <MessageCircle className="h-8 w-8 mx-auto mb-2" />
                <p>Ask a security question, e.g. "Show me suspicious activity today"</p>
              </div>
            ) : (
              copilotMessages.map((msg, i) => (
                <div key={i} className={`p-3 rounded-lg ${msg.role === 'user' ? 'bg-primary-50 dark:bg-slate-700 ml-auto max-w-[80%]' : 'bg-gray-100 dark:bg-slate-800 mr-auto max-w-[80%]'}`}>
                  <p className="text-sm">{msg.content}</p>
                  <span className="text-xs text-gray-400">{new Date(msg.timestamp).toLocaleTimeString()}</span>
                </div>
              ))
            )}
            {copilotLoading && <div className="p-3 bg-gray-100 dark:bg-slate-800 rounded-lg mr-auto"><div className="animate-pulse">AI is analyzing...</div></div>}
          </div>
          <div className="flex space-x-2">
            <input type="text" value={copilotInput} onChange={(e) => setCopilotInput(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && !copilotLoading && handleCopilotSend()}
              placeholder="Ask about security events, incidents, users..."
              className="flex-1 px-4 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 focus:ring-2 focus:ring-primary-500" disabled={copilotLoading} />
            <button onClick={handleCopilotSend} disabled={copilotLoading || !copilotInput.trim()}
              className="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-50 flex items-center">
              <Send className="h-4 w-4" />
            </button>
          </div>
          <div className="flex flex-wrap gap-2 mt-3">
            {['Show me suspicious activity today', 'What incidents are active?', 'Check events from user 42'].map((s, i) => (
              <button key={i} onClick={() => setCopilotInput(s)} className="text-xs px-3 py-1 bg-gray-100 dark:bg-slate-800 rounded-full hover:bg-gray-200">{s}</button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
};

export default SecurityTab;
         