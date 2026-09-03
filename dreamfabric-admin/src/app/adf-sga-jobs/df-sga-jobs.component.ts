import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatTableModule } from '@angular/material/table';
import { FontAwesomeModule } from '@fortawesome/angular-fontawesome';
import { faBarsProgress } from '@fortawesome/free-solid-svg-icons';
import { URLS } from '../shared/constants/urls';
import { normalizeError } from 'src/app/shared/utilities/app-error';

interface SgaJob {
  id: number;
  status: string;
  datInicio: string;
  datFim: string;
  tipo: number;
  datCadastro: string;
  refLoginCad: string;
}

interface SgaJobsReport {
  total: number;
  jobs: Array<SgaJob>;
}

/** R1 — batches internos do SGA em leitura (resource sga_login/jobs). */
@Component({
  selector: 'df-sga-jobs',
  standalone: true,
  imports: [
    CommonModule,
    MatButtonModule,
    MatCardModule,
    MatIconModule,
    MatProgressBarModule,
    MatTableModule,
    FontAwesomeModule,
  ],
  templateUrl: './df-sga-jobs.component.html',
  styleUrls: ['./df-sga-jobs.component.scss'],
})
export class DfSgaJobsComponent implements OnInit {
  readonly columns = [
    'id',
    'status',
    'datInicio',
    'datFim',
    'tipo',
    'refLoginCad',
  ];
  readonly faJobs = faBarsProgress;

  loading = false;
  error = '';
  report: SgaJobsReport | null = null;

  constructor(private http: HttpClient) {}

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    if (this.loading) {
      return;
    }
    this.loading = true;
    this.error = '';
    this.http.post<SgaJobsReport>(URLS.SGA_SYNC_JOBS, {}).subscribe({
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
