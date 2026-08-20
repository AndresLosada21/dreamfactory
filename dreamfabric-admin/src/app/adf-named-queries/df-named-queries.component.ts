import { CommonModule } from '@angular/common';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Component, OnInit } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatIconModule } from '@angular/material/icon';
import { DfPageHeaderComponent } from '../shared/components/df-page-header/df-page-header.component';

type NamedQuery = {
  id: number;
  name: string;
  description?: string;
  service_id?: number;
  serviceId?: number;
  is_active?: boolean;
  isActive?: boolean;
  lock_version?: number;
  lockVersion?: number;
  revisions?: Array<{ id: number; revision: number }>;
};

@Component({
  selector: 'df-named-queries',
  standalone: true,
  imports: [
    CommonModule,
    ReactiveFormsModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    MatProgressBarModule,
    MatIconModule,
    DfPageHeaderComponent,
  ],
  templateUrl: './df-named-queries.component.html',
  styleUrls: ['./df-named-queries.component.scss'],
})
export class DfNamedQueriesComponent implements OnInit {
  queries: NamedQuery[] = [];
  services: Array<{ id: number; name: string; label: string; type: string }> = [];
  error = '';
  saving = false;
  loading = false;
  publishingId: number | null = null;

  form = this.formBuilder.group({
    service_id: ['' as string | number, Validators.required],
    name: ['', [Validators.required, Validators.pattern(/^[A-Za-z][A-Za-z0-9_-]{0,127}$/)]],
    description: [''],
    sql: ['', Validators.required],
    parameters: ['[]', Validators.required],
    output_schema: ['[]', Validators.required],
    budgets: ['{}', Validators.required],
  });

  constructor(private http: HttpClient, private formBuilder: FormBuilder) {}

  ngOnInit(): void {
    this.refresh();
  }

  refresh(): void {
    this.error = '';
    this.loading = true;
    this.http
      .get<any>('/api/v2/system/named_query', { params: new HttpParams().set('limit', '100').set('order', 'name ASC') })
      .subscribe({
        next: response => {
          this.queries = response.resource ?? response ?? [];
          this.loading = false;
        },
        error: error => {
          this.error = error.error?.error?.message ?? 'Unable to load Named Queries.';
          this.loading = false;
        },
      });
    this.http
      .get<any>('/api/v2/system/service', { params: new HttpParams().set('limit', '100') })
      .subscribe({
        next: response => {
          const all = response.resource ?? response ?? [];
          this.services = (all as any[]).filter((service: { type: string }) =>
            ['pgsql_query', 'oracle', 'sqlsrv', 'informix'].includes(service.type)
          );
        },
        error: () => undefined,
      });
  }

  create(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    let parsed: any;
    try {
      const value = this.form.getRawValue();
      parsed = {
        ...value,
        service_id: Number(value.service_id),
        parameters: JSON.parse((value.parameters as string) ?? '[]'),
        output_schema: JSON.parse((value.output_schema as string) ?? '[]'),
        budgets: JSON.parse((value.budgets as string) ?? '{}'),
      };
      if (!Array.isArray(parsed.parameters)) throw new Error('parameters must be array');
      if (!Array.isArray(parsed.output_schema)) throw new Error('output_schema must be array');
    } catch (e: any) {
      this.error = e?.message?.includes('JSON') ? 'Parameters, output schema, and budgets must contain valid JSON.' : e.message;
      return;
    }
    this.saving = true;
    this.error = '';
    this.http.post('/api/v2/system/named_query', parsed).subscribe({
      next: () => {
        this.form.reset({ service_id: '' as any, name: '', description: '', sql: '', parameters: '[]', output_schema: '[]', budgets: '{}' });
        this.form.markAsPristine();
        this.saving = false;
        this.refresh();
      },
      error: error => {
        this.saving = false;
        const status = error.status;
        if (status === 409) {
          this.error = 'Conflict: query was modified concurrently. Refresh and retry.';
        } else {
          this.error = error.error?.error?.message ?? 'Unable to create Named Query.';
        }
      },
    });
  }

  publish(query: NamedQuery): void {
    this.publishingId = query.id;
    this.error = '';
    this.http
      .get<any>(`/api/v2/system/named_query/${query.id}`, {
        params: new HttpParams().set('related', 'revisions').set('fields', '*'),
      })
      .subscribe({
        next: response => {
          const detail: NamedQuery = (response as any).resource ?? response;
          const revisions = (detail as any).revisions ?? (detail as any).related?.revisions ?? [];
          const latest = [...(revisions as Array<{ id: number; revision: number }>)].sort((a, b) => b.revision - a.revision)[0];
          if (!latest) {
            this.error = `Named Query '${query.name}' has no revision to publish.`;
            this.publishingId = null;
            return;
          }
          const lockVal = (detail as any).lock_version ?? (detail as any).lockVersion;
          this.http
            .patch(`/api/v2/system/named_query/${query.id}`, {
              lock_version: lockVal,
              lockVersion: lockVal,
              publish_revision_id: latest.id,
              publishRevisionId: latest.id,
            } as any)
            .subscribe({
              next: () => {
                this.publishingId = null;
                this.refresh();
              },
              error: error => {
                this.publishingId = null;
                if (error.status === 409) {
                  this.error = 'Publish conflict: reload the catalog and retry.';
                } else {
                  this.error = error.error?.error?.message ?? 'Unable to publish Named Query.';
                }
              },
            });
        },
        error: error => {
          this.publishingId = null;
          this.error = error.error?.error?.message ?? 'Unable to load Named Query revisions.';
        },
      });
  }

  clearForm(): void {
    this.form.reset({ service_id: "" as any, name: "", description: "", sql: "", parameters: "[]", output_schema: "[]", budgets: "{}" });
    this.form.markAsPristine();
  }

  trackById = (_: number, item: NamedQuery) => item.id;
  trackByServiceId = (_: number, item: { id: number }) => item.id;
}
