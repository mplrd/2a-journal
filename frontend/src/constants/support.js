// Support ticket enums — kept in sync with api/src/Enums/Ticket*.php
export const TicketType = {
  SUPPORT: 'SUPPORT',
  BUG: 'BUG',
  FEATURE: 'FEATURE',
}

export const TicketStatus = {
  OPEN: 'OPEN',
  IN_PROGRESS: 'IN_PROGRESS',
  WAITING_USER: 'WAITING_USER',
  RESOLVED: 'RESOLVED',
  CLOSED: 'CLOSED',
}

export const TicketPriority = {
  LOW: 'LOW',
  NORMAL: 'NORMAL',
  HIGH: 'HIGH',
}

// PrimeVue Tag severities for visual status / priority badges
export const TICKET_STATUS_SEVERITY = {
  OPEN: 'info',
  IN_PROGRESS: 'warn',
  WAITING_USER: 'secondary',
  RESOLVED: 'success',
  CLOSED: 'contrast',
}

export const TICKET_PRIORITY_SEVERITY = {
  LOW: 'secondary',
  NORMAL: 'info',
  HIGH: 'danger',
}

export const TICKET_TYPE_ICON = {
  SUPPORT: 'pi pi-question-circle',
  BUG: 'pi pi-exclamation-triangle',
  FEATURE: 'pi pi-lightbulb',
}
