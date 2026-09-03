import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { MatButtonModule } from '@angular/material/button';
import { URLS } from '../shared/constants/urls';
import { normalizeError } from 'src/app/shared/utilities/app-error';

interface SgaAuditEvent {
  id: number;
  datAcesso: string;
  refMaquina: string;
  codUsuario: string;
  nomUsuario: string;
  nomPerfil: string;
}

interface SgaAuditReport {
  total: number;
  events: Array<SgaAuditEvent>;
  datStart: string;
  datEnd: string;
}

function isoDate(d: Date): string {
  return d.toISOString().slice(0, 10);
}

/** R3 — trilha de auditoria SGA em leitura (resource sga_login/audit). */
@Component({
  selector: 'df-sga-audit',
  standalone: true,
  imports: [CommonModule, FormsModule, MatButtonModule],
  template: `
    <h1>SGA Audit</h1>
    <p>Access trail mirrored from SGA (read-only).</p>
    <div class="sga-audit-filters">
      <label
        >From
        <input type="date" [(ngModel)]="datStart" name="datStart" />
      </label>
      <label
        >To
        <input type="date" [(ngModel)]="datEnd" name="datEnd" />
      </label>
      <button
        mat-flat-button
        color="primary"
        type="button"
        (click)="load()"
        [disabled]="loading">
        {{ loading ? 'Loading...' : 'Load' }}
      </button>
    </div>
    <p *ngIf="error" class="sga-audit-error">{{ error }}</p>
    <p *ngIf="report">Total: {{ report.total }}</p>
    <table *ngIf="report && report.events.length">
      <thead>
        <tr>
          <th>Date</th>
          <th>User</th>
          <th>Name</th>
          <th>Profile</th>
          <th>Machine</th>
        </tr>
      </thead>
      <tbody>
        <tr *ngFor="let e of report.events">
          <td>{{ e.datAcesso }}</td>
          <td>{{ e.codUsuario }}</td>
          <td>{{ e.nomUsuario }}</td>
          <td>{{ e.nomPerfil }}</td>
          <td>{{ e.refMaquina }}</td>
        </tr>
      </tbody>
    </table>
    <p *ngIf="report && !report.events.length">No events in this range.</p>
  `,
})
export class DfSgaAuditComponent implements OnInit {
  datStart = '';
  datEnd = '';
  loading = false;
  error = '';
  report: SgaAuditReport | null = null;

  constructor(private http: HttpClient) {}

  ngOnInit(): void {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - 30);
    this.datStart = isoDate(start);
    this.datEnd = isoDate(end);
    this.load();
  }

  load(): void {
    if (this.loading) {
      return;
    }
    this.loading = true;
    this.error = '';
    this.http
      .post<SgaAuditReport>(URLS.SGA_SYNC_AUDIT, {
        datStart: this.datStart,
        datEnd: this.datEnd,
      })
      .subscribe({
        next: report => {
          this.loading = false;
          this.report = report;
        },
        error: err => {
          this.loading = false;
          this.error = normalizeError(err).message;
        },
      });
  }
}
