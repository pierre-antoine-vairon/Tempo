export type Shift = {
  id: number;
  org_id: number;
  site_id: number;
  roster_id: number;
  demand_id: number | null;
  starts_at: string;
  ends_at: string;
  required_count: number;
  status: string;
  notes: string | null;
};

export type Assignment = {
  id: number;
  org_id: number;
  shift_id: number;
  worker_id: number;
  worker_first_name: string;
  worker_last_name: string;
  role: string;
  status: string;
};

export type Roster = {
  id: number;
  org_id: number;
  site_id: number;
  site_name: string;
  period_start: string;
  period_end: string;
  status: string;
  notes: string | null;
};
