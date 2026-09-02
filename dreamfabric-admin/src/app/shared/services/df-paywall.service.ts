import { Injectable } from '@angular/core';
import { catchError, map, of, switchMap } from 'rxjs';
import { DfSystemConfigDataService } from './df-system-config-data.service';
import { DfErrorService } from './df-error.service';
import { HttpClient } from '@angular/common/http';
import { normalizeError } from '../utilities/app-error';

@Injectable({
  providedIn: 'root',
})
export class DfPaywallService {
  private openSourceLockedFeatures = [
    'event-scripts',
    'rate-limiting',
    'scheduler',
    'reporting',
  ];

  private silverLockedFeatures = ['rate-limiting', 'scheduler', 'reporting'];

  isFeatureLocked(route: string, licenseType: string): boolean {
    return false; // premium Determinus — unlock all (was GOLD/SILVER check)
  }

  constructor(
    private systemConfigDataService: DfSystemConfigDataService,
    private errorService: DfErrorService,
    private http: HttpClient
  ) {}

  activatePaywall(resource?: string | Array<string>) {
    return of(false); // premium Determinus — no paywall (was system.resource check)
  }

  trackPaywallHit(
    email: string = 'Unknown. Unable to fetch email',
    ip_address: string = 'Unknown. Unable to fetch IP address',
    service_name: string = 'Service name is not specified'
  ): void {
    this.http
      .post('https://updates.dreamfactory.com/api/paywall', {
        email,
        ip_address: ip_address,
        service_name: service_name,
      })
      .subscribe({
        next: () => {},
        error: err => {
          console.error('Paywall tracking failed:', err);
        },
      });
  }
}
