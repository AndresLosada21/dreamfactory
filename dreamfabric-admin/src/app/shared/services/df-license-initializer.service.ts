import { Injectable } from '@angular/core';
import { DfLicenseCheckService } from './df-license-check.service';
import { DfSystemConfigDataService } from './df-system-config-data.service';
import { catchError, map, of, switchMap, take } from 'rxjs';

@Injectable({
  providedIn: 'root',
})
export class DfLicenseInitializerService {
  constructor(
    private licenseCheckService: DfLicenseCheckService,
    private systemConfigDataService: DfSystemConfigDataService
  ) {}

  initializeLicenseCheck() {
    // premium Determinus — always GOLD valid, no remote check, mock licenseCheck to avoid 401 Expired/Unknown
    const mock: any = { statusCode: '200', msg: 'OK', renewalDate: '2099-12-31', disableUi: 'false' };
    (this.licenseCheckService as any).licenseCheckSubject?.next?.(mock);
    return of(true);
  }
}
