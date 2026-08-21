import { ComponentFixture, TestBed } from '@angular/core/testing';
import {
  HttpClientTestingModule,
  HttpTestingController,
} from '@angular/common/http/testing';

import { DfNamedQueriesComponent } from './df-named-queries.component';

describe('DfNamedQueriesComponent', () => {
  let component: DfNamedQueriesComponent;
  let fixture: ComponentFixture<DfNamedQueriesComponent>;
  let httpMock: HttpTestingController;

  const revision = {
    id: 31,
    revision: 1,
    sql: 'SELECT :cma AS cma',
    parameters: [{ name: 'cma', type: 'string', required: true }],
    outputSchema: [{ name: 'cma' }],
    budgets: { maxRows: 1 },
  };

  const query = {
    id: 7,
    serviceId: 9,
    name: 'acasala',
    description: 'Assembly by CMA',
    isActive: true,
    publishedRevisionId: 31,
    lockVersion: 3,
    revisions: [revision],
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [DfNamedQueriesComponent, HttpClientTestingModule],
    });
    fixture = TestBed.createComponent(DfNamedQueriesComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('loads only compatible source services for a new draft', () => {
    component.refresh();

    httpMock
      .expectOne(request => request.url === '/api/v2/system/named_query')
      .flush({ resource: [] });
    httpMock
      .expectOne(request => request.url === '/api/v2/system/service')
      .flush({
        resource: [
          { id: 9, name: 'py_ptg', label: 'PY PTG', type: 'pgsql_query' },
          { id: 10, name: 'files', label: 'Files', type: 'local_file' },
        ],
      });

    expect(component.services).toEqual([
      { id: 9, name: 'py_ptg', label: 'PY PTG', type: 'pgsql_query' },
    ]);
    expect(component.isNewDefinition).toBe(true);
  });

  it('loads a selected query together with its revision history', () => {
    component.selectQuery(query);

    httpMock
      .expectOne('/api/v2/system/named_query/7')
      .flush(query);

    expect(component.selectedQuery?.name).toBe('acasala');
    expect(component.selectedRevision?.id).toBe(31);
    expect(component.definitionForm.getRawValue().sql).toBe('SELECT :cma AS cma');
    expect(component.definitionForm.controls.name.disabled).toBe(true);
    expect(component.definitionForm.controls.serviceId.disabled).toBe(true);
  });

  it('publishes the explicitly selected revision instead of assuming the latest', () => {
    component.selectedQuery = {
      ...query,
      revisions: [revision, { ...revision, id: 32, revision: 2 }],
    };
    component.selectedRevision = component.selectedQuery.revisions[0];

    component.publishSelectedRevision();

    const request = httpMock.expectOne('/api/v2/system/named_query/7');
    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({
      lockVersion: 3,
      publishRevisionId: 31,
    });
    request.flush({});

    httpMock
      .expectOne(request => request.url === '/api/v2/system/named_query')
      .flush({ resource: [] });
    httpMock
      .expectOne(request => request.url === '/api/v2/system/service')
      .flush({ resource: [] });
  });

  it('runs the published endpoint and retains returned column names', () => {
    component.selectedQuery = query;
    component.selectedRevision = revision;
    component.services = [
      { id: 9, name: 'py_ptg', label: 'PY PTG', type: 'pgsql_query' },
    ];
    component.runForm.setValue({ parameters: '{"cma":"KE1200055820"}' });

    component.runPublishedQuery();

    const request = httpMock.expectOne('/api/v2/py_ptg/_query/acasala');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ cma: 'KE1200055820' });
    request.flush({ resource: [{ nr_carcaca: 'KE120-0055820' }] });

    expect(component.resultColumns).toEqual(['nr_carcaca']);
    expect(component.resultRows).toEqual([{ nr_carcaca: 'KE120-0055820' }]);
    expect(component.runFinished).toBe(true);
    expect(component.serviceEndpointFor(9)).toBe('py_ptg');
  });
});
