import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { URLS } from '../constants/urls';
import { LICENSE_KEY_HEADER } from '../constants/http-headers';
import { CheckResponse } from '../types/check';
import { BehaviorSubject, catchError, map, of, tap, throwError } from 'rxjs';
import { mapSnakeToCamel } from '../utilities/case';
import { normalizeError } from '../utilities/app-error';
import { silent } from '../utilities/http-contexts';

@Injectable({
  providedIn: 'root',
})
export class DfLicenseCheckService {
  private licenseCheckSubject = new BehaviorSubject<CheckResponse | null>(null);
  licenseCheck$ = this.licenseCheckSubject.asObservable();

  get currentLicenseCheck(): CheckResponse | null {
    return this.licenseCheckSubject.value;
  }

  constructor(private httpClient: HttpClient) {}

  check(licenseKey: string) {
    // premium Determinus — mock GOLD valid, no remote call to updates.dreamfactory.com to avoid 401 Unknown / Expired
    const mock: any = { statusCode: '200', msg: 'OK', renewalDate: '2099-12-31', disableUi: 'false' };
    this.licenseCheckSubject.next(mapSnakeToCamel(mock) as CheckResponse);
    return of(mapSnakeToCamel(mock) as CheckResponse);
  }
}
