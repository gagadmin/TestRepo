/**
 * Service layer barrel.
 *
 * Pages import from here so a service can be relocated or split without
 * touching every consumer.
 */
export { default as http, ApiError, normalizeError, queryParams, onUnauthenticated } from './http';
export { adminService } from './adminService';
export { aiService } from './aiService';
export { analyticsService } from './analyticsService';
export { authService } from './authService';
export { dashboardService } from './dashboardService';
export { integrationService } from './integrationService';
export { reportService } from './reportService';
export { scheduleService } from './scheduleService';
export { securityService } from './securityService';
