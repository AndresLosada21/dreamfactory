import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatTableModule } from '@angular/material/table';
import { FontAwesomeModule } from '@fortawesome/angular-fontawesome';
import { faClockRotateLeft } from '@fortawesome/free-solid-svg-icons';
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
  imports: [
    CommonModule,
    FormsModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatTableModule,
    FontAwesomeModule,
  ],
  templateUrl: './df-sga-audit.component.html',
  styleUrls: ['./df-sga-audit.component.scss'],
})
export class DfSgaAuditComponent implements OnInit {
  readonly columns = [
    'datAcesso',
    'codUsuario',
    'nomUsuario',
    'nomPerfil',
    'refMaquina',
  ];
  readonly faHistory = faClockRotateLeft;

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
          this.report = null;
          this.error = normalizeError(err).message;
        },
      });
  }
}
