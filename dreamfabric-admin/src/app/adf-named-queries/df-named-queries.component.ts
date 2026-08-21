import { CommonModule } from '@angular/common';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Component, OnInit } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { forkJoin } from 'rxjs';

import { DfPageHeaderComponent } from '../shared/components/df-page-header/df-page-header.component';

type JsonObject = Record<string, unknown>;

type NamedQueryParameter = {
  name: string;
  type?: string;
  required?: boolean;
  default?: unknown;
};

type NamedQueryRevision = {
  id: number;
  revision: number;
  sql: string;
  parameters: NamedQueryParameter[];
  outputSchema: unknown[];
  budgets: JsonObject;
  createdDate?: string;
};

type NamedQuery = {
  id: number;
  serviceId: number;
  name: string;
  description: string;
  isActive: boolean;
  publishedRevisionId: number | null;
  lockVersion: number;
  revisions: NamedQueryRevision[];
  createdDate?: string;
  lastModifiedDate?: string;
};

type SourceService = {
  id: number;
  name: string;
  label: string;
  type: string;
};

type DefinitionPayload = {
  serviceId: number;
  name: string;
  description: string | null;
  sql: string;
  parameters: NamedQueryParameter[];
  outputSchema: unknown[];
  budgets: JsonObject;
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
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    DfPageHeaderComponent,
  ],
  templateUrl: './df-named-queries.component.html',
  styleUrls: ['./df-named-queries.component.scss'],
})
export class DfNamedQueriesComponent implements OnInit {
  queries: NamedQuery[] = [];
  services: SourceService[] = [];
  selectedQuery: NamedQuery | null = null;
  selectedRevision: NamedQueryRevision | null = null;
  resultRows: Array<Record<string, unknown>> = [];
  resultColumns: string[] = [];
  error = '';
  loading = false;
  loadingDetail = false;
  saving = false;
  publishing = false;
  deleting = false;
  running = false;
  runFinished = false;

  definitionForm = this.formBuilder.nonNullable.group({
    serviceId: [0, [Validators.required, Validators.min(1)]],
    name: ['', [Validators.required, Validators.pattern(/^[A-Za-z][A-Za-z0-9_-]{0,127}$/)]],
    description: [''],
    sql: ['', Validators.required],
    parameters: ['[]', Validators.required],
    outputSchema: ['[]', Validators.required],
    budgets: ['{}', Validators.required],
  });

  runForm = this.formBuilder.nonNullable.group({
    parameters: ['{}', Validators.required],
  });

  constructor(private http: HttpClient, private formBuilder: FormBuilder) {}

  ngOnInit(): void {
    this.refresh();
  }

  get isNewDefinition(): boolean {
    return this.selectedQuery === null;
  }

  get publishedRevision(): NamedQueryRevision | null {
    if (!this.selectedQuery?.publishedRevisionId) {
      return null;
    }

    return (
      this.selectedQuery.revisions.find(
        revision => revision.id === this.selectedQuery?.publishedRevisionId
      ) ?? null
    );
  }

  refresh(selectedId: number | null = this.selectedQuery?.id ?? null): void {
    this.error = '';
    this.loading = true;

    forkJoin({
      queries: this.http.get<unknown>('/api/v2/system/named_query', {
        params: new HttpParams().set('limit', '100').set('order', 'name ASC'),
      }),
      services: this.http.get<unknown>('/api/v2/system/service', {
        params: new HttpParams().set('limit', '100'),
      }),
    }).subscribe({
      next: response => {
        this.queries = this.resourceArray(response.queries).map(query =>
          this.toQuery(query)
        );
        this.services = this.resourceArray(response.services)
          .map(service => this.toService(service))
          .filter(service =>
            ['pgsql_query', 'oracle', 'sqlsrv', 'informix'].includes(service.type)
          );
        this.loading = false;

        const query =
          this.queries.find(item => item.id === selectedId) ?? this.queries[0];
        if (query) {
          this.selectQuery(query);
        } else {
          this.startCreate();
        }
      },
      error: error => {
        this.loading = false;
        this.error = this.errorMessage(error, 'Unable to load Named Queries and source services.');
      },
    });
  }

  selectQuery(query: NamedQuery): void {
    this.error = '';
    this.loadingDetail = true;
    this.runFinished = false;
    this.resultRows = [];
    this.resultColumns = [];

    this.http.get<unknown>(`/api/v2/system/named_query/${query.id}`).subscribe({
      next: response => {
        this.selectedQuery = this.toQuery(this.resource(response));
        this.selectedRevision =
          this.publishedRevision ?? this.selectedQuery.revisions[0] ?? null;
        this.populateDefinitionForm();
        this.populateRunParameters();
        this.loadingDetail = false;
      },
      error: error => {
        this.loadingDetail = false;
        this.error = this.errorMessage(error, 'Unable to load Named Query details.');
      },
    });
  }

  startCreate(): void {
    this.selectedQuery = null;
    this.selectedRevision = null;
    this.resultRows = [];
    this.resultColumns = [];
    this.runFinished = false;
    this.definitionForm.controls.serviceId.enable({ emitEvent: false });
    this.definitionForm.controls.name.enable({ emitEvent: false });
    this.definitionForm.reset({
      serviceId: 0,
      name: '',
      description: '',
      sql: '',
      parameters: '[]',
      outputSchema: '[]',
      budgets: '{}',
    });
    this.definitionForm.markAsPristine();
    this.runForm.reset({ parameters: '{}' });
    this.runForm.markAsPristine();
  }

  selectRevision(revisionId: number): void {
    const revision = this.selectedQuery?.revisions.find(
      item => item.id === Number(revisionId)
    );
    if (!revision) {
      return;
    }

    this.selectedRevision = revision;
    this.populateDefinitionForm();
    this.runFinished = false;
    this.resultRows = [];
    this.resultColumns = [];
  }

  saveDefinition(): void {
    if (this.definitionForm.invalid) {
      this.definitionForm.markAllAsTouched();
      return;
    }

    const definition = this.definitionPayload();
    if (!definition) {
      return;
    }

    this.saving = true;
    this.error = '';
    if (!this.selectedQuery) {
      this.http.post<unknown>('/api/v2/system/named_query', definition).subscribe({
        next: response => {
          this.saving = false;
          this.refresh(this.toQuery(this.resource(response)).id);
        },
        error: error => {
          this.saving = false;
          this.error = this.errorMessage(error, 'Unable to create Named Query.');
        },
      });
      return;
    }

    this.http
      .patch<unknown>(`/api/v2/system/named_query/${this.selectedQuery.id}`, {
        ...definition,
        lockVersion: this.selectedQuery.lockVersion,
      })
      .subscribe({
        next: () => {
          this.saving = false;
          this.refresh(this.selectedQuery?.id ?? null);
        },
        error: error => {
          this.saving = false;
          this.error =
            error.status === 409
              ? 'Revision conflict: the query changed. The catalog was reloaded.'
              : this.errorMessage(error, 'Unable to create a Named Query revision.');
          if (error.status === 409) {
            this.refresh(this.selectedQuery?.id ?? null);
          }
        },
      });
  }

  publishSelectedRevision(): void {
    if (!this.selectedQuery || !this.selectedRevision) {
      return;
    }

    this.publishing = true;
    this.error = '';
    this.http
      .patch<unknown>(`/api/v2/system/named_query/${this.selectedQuery.id}`, {
        lockVersion: this.selectedQuery.lockVersion,
        publishRevisionId: this.selectedRevision.id,
      })
      .subscribe({
        next: () => {
          this.publishing = false;
          this.refresh(this.selectedQuery?.id ?? null);
        },
        error: error => {
          this.publishing = false;
          this.error =
            error.status === 409
              ? 'Publish conflict: the query changed. The catalog was reloaded.'
              : this.errorMessage(error, 'Unable to publish Named Query.');
          if (error.status === 409) {
            this.refresh(this.selectedQuery?.id ?? null);
          }
        },
      });
  }

  deleteSelectedQuery(): void {
    if (
      !this.selectedQuery ||
      !window.confirm(
        `Delete Named Query "${this.selectedQuery.name}" and every immutable revision? This cannot be undone.`
      )
    ) {
      return;
    }

    const queryId = this.selectedQuery.id;
    this.deleting = true;
    this.error = '';
    this.http.delete<unknown>(`/api/v2/system/named_query/${queryId}`).subscribe({
      next: () => {
        this.deleting = false;
        this.refresh();
      },
      error: error => {
        this.deleting = false;
        this.error = this.errorMessage(error, 'Unable to delete Named Query.');
      },
    });
  }

  runPublishedQuery(): void {
    if (!this.selectedQuery || !this.publishedRevision) {
      return;
    }

    let parameters: JsonObject;
    try {
      const parsed: unknown = JSON.parse(this.runForm.controls.parameters.value);
      if (!this.isJsonObject(parsed)) {
        throw new Error('Execution parameters must be a JSON object.');
      }
      parameters = parsed;
    } catch (error: unknown) {
      this.error =
        error instanceof Error && error.message === 'Execution parameters must be a JSON object.'
          ? error.message
          : 'Execution parameters must contain valid JSON.';
      return;
    }

    const service = this.services.find(
      item => item.id === this.selectedQuery?.serviceId
    );
    if (!service) {
      this.error = 'The source service is unavailable. Refresh the catalog and retry.';
      return;
    }

    this.running = true;
    this.error = '';
    this.runFinished = false;
    this.resultRows = [];
    this.resultColumns = [];
    this.http
      .post<unknown>(
        `/api/v2/${encodeURIComponent(service.name)}/_query/${encodeURIComponent(this.selectedQuery.name)}`,
        parameters
      )
      .subscribe({
        next: response => {
          const resource = this.resource(response);
          this.resultRows = Array.isArray(resource)
            ? resource.filter(this.isJsonObject)
            : [];
          this.resultColumns = [
            ...new Set(this.resultRows.flatMap(row => Object.keys(row))),
          ];
          this.running = false;
          this.runFinished = true;
        },
        error: error => {
          this.running = false;
          this.error = this.errorMessage(error, 'Unable to execute Named Query.');
        },
      });
  }

  discardDefinitionChanges(): void {
    if (this.selectedQuery && this.selectedRevision) {
      this.populateDefinitionForm();
      return;
    }

    this.startCreate();
  }

  serviceNameFor(serviceId: number): string {
    const service = this.services.find(item => item.id === serviceId);
    return service?.label || service?.name || `service #${serviceId}`;
  }

  serviceEndpointFor(serviceId: number): string {
    return this.services.find(item => item.id === serviceId)?.name ?? `service-${serviceId}`;
  }

  parameterCount(revision: NamedQueryRevision | null): number {
    return revision?.parameters.length ?? 0;
  }

  formatCell(value: unknown): string {
    if (value === null || value === undefined) {
      return 'null';
    }
    if (typeof value === 'object') {
      return JSON.stringify(value) ?? '';
    }
    return String(value);
  }

  trackById = (_: number, item: { id: number }): number => item.id;

  private populateDefinitionForm(): void {
    if (!this.selectedQuery || !this.selectedRevision) {
      return;
    }

    this.definitionForm.reset({
      serviceId: this.selectedQuery.serviceId,
      name: this.selectedQuery.name,
      description: this.selectedQuery.description,
      sql: this.selectedRevision.sql,
      parameters: this.jsonText(this.selectedRevision.parameters),
      outputSchema: this.jsonText(this.selectedRevision.outputSchema),
      budgets: this.jsonText(this.selectedRevision.budgets),
    });
    this.definitionForm.controls.serviceId.disable({ emitEvent: false });
    this.definitionForm.controls.name.disable({ emitEvent: false });
    this.definitionForm.markAsPristine();
  }

  private populateRunParameters(): void {
    const parameters: JsonObject = {};
    for (const declaration of this.publishedRevision?.parameters ?? []) {
      if (Object.prototype.hasOwnProperty.call(declaration, 'default')) {
        parameters[declaration.name] = declaration.default;
      } else if (declaration.required) {
        parameters[declaration.name] = '';
      }
    }

    this.runForm.reset({ parameters: this.jsonText(parameters) });
    this.runForm.markAsPristine();
  }

  private definitionPayload(): DefinitionPayload | null {
    const value = this.definitionForm.getRawValue();
    try {
      const parameters: unknown = JSON.parse(value.parameters);
      const outputSchema: unknown = JSON.parse(value.outputSchema);
      const budgets: unknown = JSON.parse(value.budgets);
      if (!Array.isArray(parameters)) {
        throw new Error('Parameters must be a JSON array.');
      }
      if (!Array.isArray(outputSchema)) {
        throw new Error('Output schema must be a JSON array.');
      }
      if (!this.isJsonObject(budgets)) {
        throw new Error('Budgets must be a JSON object.');
      }

      return {
        serviceId: Number(value.serviceId),
        name: value.name.trim(),
        description: value.description.trim() || null,
        sql: value.sql,
        parameters: parameters as NamedQueryParameter[],
        outputSchema,
        budgets,
      };
    } catch (error: unknown) {
      this.error = error instanceof Error ? error.message : 'Definition fields must contain valid JSON.';
      return null;
    }
  }

  private toQuery(value: unknown): NamedQuery {
    const record = this.asObject(value);
    const related = this.asObject(record['related']);
    const revisions = record['revisions'] ?? related['revisions'] ?? [];
    return {
      id: Number(record['id']),
      serviceId: Number(record['serviceId'] ?? record['service_id']),
      name: String(record['name'] ?? ''),
      description: String(record['description'] ?? ''),
      isActive: Boolean(record['isActive'] ?? record['is_active']),
      publishedRevisionId: this.positiveNumber(
        record['publishedRevisionId'] ?? record['published_revision_id']
      ),
      lockVersion: Number(record['lockVersion'] ?? record['lock_version'] ?? 1),
      revisions: Array.isArray(revisions)
        ? revisions.map(revision => this.toRevision(revision))
        : [],
      createdDate: this.stringOrUndefined(record['createdDate'] ?? record['created_date']),
      lastModifiedDate: this.stringOrUndefined(
        record['lastModifiedDate'] ?? record['last_modified_date']
      ),
    };
  }

  private toRevision(value: unknown): NamedQueryRevision {
    const record = this.asObject(value);
    const outputSchema = record['outputSchema'] ?? record['output_schema'] ?? [];
    const parameters = Array.isArray(record['parameters'])
      ? record['parameters']
          .map(parameter => this.toParameter(parameter))
          .filter((parameter): parameter is NamedQueryParameter => parameter !== null)
      : [];
    return {
      id: Number(record['id']),
      revision: Number(record['revision']),
      sql: String(record['sql'] ?? ''),
      parameters,
      outputSchema: Array.isArray(outputSchema) ? outputSchema : [],
      budgets: this.isJsonObject(record['budgets']) ? record['budgets'] : {},
      createdDate: this.stringOrUndefined(record['createdDate'] ?? record['created_date']),
    };
  }

  private toService(value: unknown): SourceService {
    const record = this.asObject(value);
    return {
      id: Number(record['id']),
      name: String(record['name'] ?? ''),
      label: String(record['label'] ?? record['name'] ?? ''),
      type: String(record['type'] ?? ''),
    };
  }

  private toParameter(value: unknown): NamedQueryParameter | null {
    const record = this.asObject(value);
    if (typeof record['name'] !== 'string') {
      return null;
    }

    const parameter: NamedQueryParameter = { name: record['name'] };
    if (typeof record['type'] === 'string') {
      parameter.type = record['type'];
    }
    if (typeof record['required'] === 'boolean') {
      parameter.required = record['required'];
    }
    if (Object.prototype.hasOwnProperty.call(record, 'default')) {
      parameter.default = record['default'];
    }
    return parameter;
  }

  private resource(response: unknown): unknown {
    const wrapped = this.asObject(response);
    return wrapped['resource'] ?? response;
  }

  private resourceArray(response: unknown): unknown[] {
    const resource = this.resource(response);
    return Array.isArray(resource) ? resource : [];
  }

  private positiveNumber(value: unknown): number | null {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
  }

  private jsonText(value: unknown): string {
    return JSON.stringify(value, null, 2);
  }

  private isJsonObject(value: unknown): value is JsonObject {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
  }

  private asObject(value: unknown): JsonObject {
    return this.isJsonObject(value) ? value : {};
  }

  private stringOrUndefined(value: unknown): string | undefined {
    return typeof value === 'string' ? value : undefined;
  }

  private errorMessage(error: unknown, fallback: string): string {
    const response = this.asObject(error);
    const body = this.asObject(response['error']);
    const nested = this.asObject(body['error']);
    return this.stringOrUndefined(nested['message'] ?? body['message']) ?? fallback;
  }
}
