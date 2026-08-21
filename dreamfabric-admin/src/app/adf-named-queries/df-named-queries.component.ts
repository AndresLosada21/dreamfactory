import { CommonModule } from '@angular/common';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Component, OnInit } from '@angular/core';
import {
  FormArray,
  FormBuilder,
  FormControl,
  FormGroup,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatTooltipModule } from '@angular/material/tooltip';
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

type QueryStatus = {
  label: string;
  cls: string;
  hint?: string;
};

const PARAMETER_TYPES = ['string', 'integer', 'number', 'boolean'];
const PARAMETER_NAME_PATTERN = /^[A-Za-z_][A-Za-z0-9_]*$/;

@Component({
  selector: 'df-named-queries',
  standalone: true,
  imports: [
    CommonModule,
    ReactiveFormsModule,
    MatButtonModule,
    MatCardModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    MatTooltipModule,
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

  // Catalog toolbar (item D).
  searchText = '';
  serviceFilter: number | 'all' = 'all';

  // Test panel (item C).
  copiedUrl = false;
  private copiedTimer: ReturnType<typeof setTimeout> | null = null;

  parameterTypes = PARAMETER_TYPES;

  definitionForm = this.formBuilder.nonNullable.group({
    serviceId: [0, [Validators.required, Validators.min(1)]],
    name: ['', [Validators.required, Validators.pattern(/^[A-Za-z][A-Za-z0-9_-]{0,127}$/)]],
    description: [''],
    sql: ['', Validators.required],
    outputSchema: ['[]'],
    budgetsMaxRows: this.formBuilder.control<number | null>(null),
    parameterRows: this.formBuilder.array<FormGroup>([]),
  });

  runParamsForm = this.formBuilder.nonNullable.group({});

  constructor(private http: HttpClient, private formBuilder: FormBuilder) {}

  ngOnInit(): void {
    this.refresh();
  }

  get isNewDefinition(): boolean {
    return this.selectedQuery === null;
  }

  get parameterRows(): FormArray<FormGroup> {
    return this.definitionForm.controls.parameterRows;
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

  get filteredQueries(): NamedQuery[] {
    const needle = this.searchText.trim().toLowerCase();
    return this.queries.filter(query => {
      if (
        this.serviceFilter !== 'all' &&
        query.serviceId !== this.serviceFilter
      ) {
        return false;
      }
      if (!needle) {
        return true;
      }
      return (
        query.name.toLowerCase().includes(needle) ||
        query.description.toLowerCase().includes(needle)
      );
    });
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
          this.queries.find(item => item.id === selectedId) ?? this.filteredQueries[0] ?? this.queries[0];
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
      outputSchema: '[]',
      budgetsMaxRows: null,
    });
    this.parameterRows.clear();
    this.definitionForm.markAsPristine();
    this.runParamsForm = this.formBuilder.nonNullable.group({});
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

  // ---- Parameter rows editor (item A) -------------------------------------

  addParameterRow(parameter?: Partial<NamedQueryParameter>): void {
    const row = this.formBuilder.nonNullable.group({
      name: [
        parameter?.name ?? '',
        [
          Validators.required,
          Validators.pattern(PARAMETER_NAME_PATTERN),
        ],
      ],
      type: [parameter?.type ?? 'string', Validators.required],
      required: [parameter?.required ?? true],
      defaultValue: [
        parameter?.default === null || parameter?.default === undefined
          ? ''
          : String(parameter.default),
      ],
    });
    this.parameterRows.push(row);
  }

  removeParameterRow(index: number): void {
    this.parameterRows.removeAt(index);
  }

  detectParametersFromSql(): void {
    const sql = this.definitionForm.controls.sql.value;
    const found = new Set<string>();
    const pattern = /(?<![\w]):([A-Za-z_][A-Za-z0-9_]*)/g;
    let match: RegExpExecArray | null;
    while ((match = pattern.exec(sql)) !== null) {
      found.add(match[1]);
    }

    const existing = new Set(
      this.parameterRows.controls.map(
        row => row.controls['name'].value.trim().toLowerCase()
      )
    );

    let added = 0;
    for (const name of found) {
      if (!existing.has(name.toLowerCase())) {
        this.addParameterRow({ name, type: 'string', required: true });
        added += 1;
      }
    }

    if (!added && found.size) {
      // All detected parameters already exist — nothing to do.
    }
  }

  private syncParameterRows(revision: NamedQueryRevision | null): void {
    this.parameterRows.clear();
    for (const parameter of revision?.parameters ?? []) {
      this.addParameterRow(parameter);
    }
  }

  private buildParametersPayload(): NamedQueryParameter[] | null {
    const rows = this.parameterRows.controls;
    const seen = new Set<string>();
    const parameters: NamedQueryParameter[] = [];

    for (const row of rows) {
      const name = row.controls['name'].value.trim();
      if (!name) {
        this.error = 'Every parameter needs a name (or remove the empty row).';
        return null;
      }
      if (!PARAMETER_NAME_PATTERN.test(name)) {
        this.error = `Invalid parameter name "${name}". Use letters, numbers and underscores, starting with a letter or underscore.`;
        return null;
      }
      const key = name.toLowerCase();
      if (seen.has(key)) {
        this.error = `Duplicate parameter name "${name}".`;
        return null;
      }
      seen.add(key);

      const rawDefault = row.controls['defaultValue'].value;
      const parameter: NamedQueryParameter = {
        name,
        type: row.controls['type'].value,
        required: row.controls['required'].value,
      };
      if (rawDefault !== '') {
        parameter.default = this.coerceDefault(rawDefault, row.controls['type'].value);
      }
      parameters.push(parameter);
    }

    return parameters;
  }

  private coerceDefault(raw: string, type: string): unknown {
    if (type === 'integer') {
      const parsed = Number.parseInt(raw, 10);
      return Number.isNaN(parsed) ? raw : parsed;
    }
    if (type === 'number') {
      const parsed = Number(raw);
      return Number.isNaN(parsed) ? raw : parsed;
    }
    if (type === 'boolean') {
      return raw === 'true' || raw === '1';
    }
    return raw;
  }

  // ---- Status badges (item B) ---------------------------------------------

  statusFor(query: NamedQuery): QueryStatus {
    if (!query.isActive || !query.publishedRevisionId) {
      return { label: 'Draft', cls: 'badge-draft' };
    }

    const latest = query.revisions.reduce<NamedQueryRevision | null>(
      (acc, revision) =>
        !acc || revision.revision > acc.revision ? revision : acc,
      null
    );
    if (latest && latest.id !== query.publishedRevisionId) {
      return {
        label: 'Pending publish',
        cls: 'badge-pending',
        hint: `Revision ${latest.revision} is saved but not published yet.`,
      };
    }

    return { label: 'Published', cls: 'badge-published' };
  }

  // ---- Save / publish / delete --------------------------------------------

  saveDefinition(): void {
    if (this.definitionForm.invalid) {
      this.definitionForm.markAllAsTouched();
      return;
    }

    const parameters = this.buildParametersPayload();
    if (parameters === null) {
      return;
    }

    const value = this.definitionForm.getRawValue();
    let outputSchema: unknown[];
    try {
      const parsed: unknown = JSON.parse(value.outputSchema || '[]');
      if (!Array.isArray(parsed)) {
        throw new Error('Output schema must be a JSON array.');
      }
      outputSchema = parsed;
    } catch {
      this.error = 'Output schema must contain a valid JSON array.';
      return;
    }

    const budgets: JsonObject = {};
    if (value.budgetsMaxRows !== null && value.budgetsMaxRows !== undefined) {
      budgets['maxRows'] = value.budgetsMaxRows;
    }

    const definition: DefinitionPayload = {
      serviceId: Number(value.serviceId),
      name: value.name.trim(),
      description: value.description.trim() || null,
      sql: value.sql,
      parameters,
      outputSchema,
      budgets,
    };

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

  // ---- Test panel (item C) --------------------------------------------------

  fullEndpointUrl(): string {
    if (!this.selectedQuery) {
      return '';
    }
    return `${location.origin}/api/v2/${this.serviceEndpointFor(
      this.selectedQuery.serviceId
    )}/_query/${this.selectedQuery.name}`;
  }

  copyEndpointUrl(): void {
    const url = this.fullEndpointUrl();
    if (!url) {
      return;
    }
    navigator.clipboard.writeText(url).then(() => {
      this.copiedUrl = true;
      if (this.copiedTimer) {
        clearTimeout(this.copiedTimer);
      }
      this.copiedTimer = setTimeout(() => (this.copiedUrl = false), 1600);
    });
  }

  runParamType(parameter: NamedQueryParameter): string {
    return parameter.type === 'integer' || parameter.type === 'number'
      ? 'number'
      : 'text';
  }

  hasDefault(parameter: NamedQueryParameter): boolean {
    return (
      Object.prototype.hasOwnProperty.call(parameter, 'default') &&
      parameter.default !== null &&
      parameter.default !== undefined &&
      parameter.default !== ''
    );
  }

  runPublishedQuery(): void {
    if (!this.selectedQuery || !this.publishedRevision) {
      return;
    }

    const parameters: JsonObject = {};
    for (const declaration of this.publishedRevision.parameters) {
      const control = this.runParamsForm.get(declaration.name);
      const raw = control?.value;
      const isEmpty =
        raw === null ||
        raw === undefined ||
        (typeof raw === 'string' && raw.trim() === '');

      if (isEmpty) {
        if (declaration.required) {
          this.error = `Parameter "${declaration.name}" is required.`;
          return;
        }
        continue;
      }

      if (declaration.type === 'integer') {
        const parsed = Number.parseInt(String(raw), 10);
        if (Number.isNaN(parsed)) {
          this.error = `Parameter "${declaration.name}" must be an integer.`;
          return;
        }
        parameters[declaration.name] = parsed;
      } else if (declaration.type === 'number') {
        const parsed = Number(raw);
        if (Number.isNaN(parsed)) {
          this.error = `Parameter "${declaration.name}" must be numeric.`;
          return;
        }
        parameters[declaration.name] = parsed;
      } else if (declaration.type === 'boolean') {
        parameters[declaration.name] =
          raw === true || raw === 'true' || raw === '1';
      } else {
        parameters[declaration.name] = String(raw);
      }
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

  trackByParameterName = (_: number, item: NamedQueryParameter): string => item.name;

  private populateDefinitionForm(): void {
    if (!this.selectedQuery || !this.selectedRevision) {
      return;
    }

    this.syncParameterRows(this.selectedRevision);
    const maxRows = this.selectedRevision.budgets['maxRows'];

    this.definitionForm.reset({
      serviceId: this.selectedQuery.serviceId,
      name: this.selectedQuery.name,
      description: this.selectedQuery.description,
      sql: this.selectedRevision.sql,
      outputSchema: this.jsonText(this.selectedRevision.outputSchema),
      budgetsMaxRows:
        typeof maxRows === 'number' ? maxRows : null,
    });
    this.definitionForm.controls.serviceId.disable({ emitEvent: false });
    this.definitionForm.controls.name.disable({ emitEvent: false });
    this.definitionForm.markAsPristine();
  }

  private populateRunParameters(): void {
    const controls: Record<string, FormControl> = {};
    for (const declaration of this.publishedRevision?.parameters ?? []) {
      const initial = this.hasDefault(declaration)
        ? declaration.default
        : declaration.required
          ? ''
          : null;
      controls[declaration.name] = this.formBuilder.nonNullable.control(
        initial as string | number | boolean,
      );
    }

    this.runParamsForm = this.formBuilder.nonNullable.group(controls);
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
