import {
  AfterViewInit,
  Component,
  ElementRef,
  ViewChild,
  Input,
  OnInit,
} from '@angular/core';
import { TranslocoPipe } from '@ngneat/transloco';
import { DfUserDataService } from '../../services/df-user-data.service';
import { DfSystemConfigDataService } from '../../services/df-system-config-data.service';
import { DfPaywallService } from '../../services/df-paywall.service';

@Component({
  selector: 'df-paywall',
  templateUrl: './df-paywall.component.html',
  styleUrls: ['./df-paywall.component.scss'],
  standalone: true,
  imports: [TranslocoPipe],
})
export class DfPaywallComponent implements AfterViewInit, OnInit {
  @ViewChild('calendlyWidget') calendlyWidget: ElementRef;
  @Input() serviceName: string;

  constructor(
    private userDataService: DfUserDataService,
    private systemConfigService: DfSystemConfigDataService,
    private dfPaywallService: DfPaywallService
  ) {}

  ngOnInit(): void {
    const user = this.userDataService.userData;
    const email = user?.email;
    const ip = this.systemConfigService?.environment?.client?.ipAddress;
    const serviceName = this.serviceName;

    this.dfPaywallService.trackPaywallHit(email, ip, serviceName);
  }

  ngAfterViewInit(): void {
    (window as any)['Calendly'].initInlineWidget({
      url: 'https://calendly.com/dreamfactory-platform/unlock-all-features',
      parentElement: this.calendlyWidget.nativeElement,
      autoLoad: false,
    });
  }
}
