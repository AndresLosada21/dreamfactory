import { Component, OnInit, ViewChild } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { MatButtonModule } from '@angular/material/button';
import { DfPaywallComponent } from 'src/app/shared/components/df-paywall/df-paywall.component';
import { DfManageServicesTableComponent } from './df-manage-services-table.component';
import { URLS } from '../../shared/constants/urls';
import { normalizeError } from 'src/app/shared/utilities/app-error';
import { ActivatedRoute } from '@angular/router';
import { NgIf } from '@angular/common';
import { DfSnackbarService } from 'src/app/shared/services/df-snackbar.service';

interface SgaSyncReport {
  total: number;
  created: Array<{ service: string }>;
  updated: Array<{ service: string }>;
  skipped: Array<{ reason: string }>;
  needs_attention: Array<{ service: string; reason: string }>;
}

@Component({
  selector: 'df-manage-services',
  templateUrl: './df-manage-services.component.html',
  standalone: true,
  imports: [DfPaywallComponent, DfManageServicesTableComponent, NgIf, MatButtonModule],
})
export class DfManageServicesComponent implements OnInit {
  paywall = false;
  showSgaSync = false;
  syncing = false;
  @ViewChild(DfManageServicesTableComponent)
  table?: DfManageServicesTableComponent;
  constructor(
    private activatedRoute: ActivatedRoute,
    private snackbarService: DfSnackbarService,
    private http: HttpClient
  ) {}

  ngOnInit(): void {
    this.activatedRoute.data.subscribe(({ data }) => {
      this.paywall = !!data?.serviceTypes && data.serviceTypes.length === 0;
    });
    this.showSgaSync = this.routeGroups().includes('Database');
    this.snackbarService.setSnackbarLastEle('', false);
  }

  private routeGroups(): Array<string> {
    let route: ActivatedRoute | null = this.activatedRoute;
    while (route) {
      const groups = route.snapshot.data?.['groups'];
      if (Array.isArray(groups) && groups.length) {
        return groups as Array<string>;
      }
      route = route.parent;
    }
    return [];
  }

  /** Traz do SGA/SGC os databases do sistema DF para a arvore do DF. */
  syncSga(): void {
    if (this.syncing) {
      return;
    }
    this.syncing = true;
    this.http.post<SgaSyncReport>(URLS.SGA_SYNC_CONNECTIONS, {}).subscribe({
      next: report => {
        this.syncing = false;
        const created = report.created?.length ?? 0;
        const updated = report.updated?.length ?? 0;
        const skipped = report.skipped?.length ?? 0;
        const attention = report.needs_attention?.length ?? 0;
        this.snackbarService.openSnackBar(
          `Sync SGA: ${created} criados, ${updated} atualizados, ${skipped} pulados, ${attention} p/ conferir.`,
          'success'
        );
        this.table?.refreshTable();
      },
      error: err => {
        this.syncing = false;
        this.snackbarService.openSnackBar(normalizeError(err).message, 'error');
      },
    });
  }
}
