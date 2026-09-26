import { NativeTabs } from 'expo-router/unstable-native-tabs';

import { Colors, Fonts } from '@/constants/theme';

/** iOS: real UITabBar (Liquid Glass on iOS 26) with SF Symbols. */
export default function AppTabs() {
  return (
    <NativeTabs
      tintColor={Colors.forest}
      iconColor={{ default: Colors.muted, selected: Colors.forest }}
      labelStyle={{ default: { fontFamily: Fonts.medium }, selected: { fontFamily: Fonts.semibold, color: Colors.forest } }}>
      <NativeTabs.Trigger name="index">
        <NativeTabs.Trigger.Label>Schedule</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'calendar', selected: 'calendar' }} md="calendar_month" />
      </NativeTabs.Trigger>
      <NativeTabs.Trigger name="bookings">
        <NativeTabs.Trigger.Label>My classes</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'checkmark.circle', selected: 'checkmark.circle.fill' }} md="event_available" />
      </NativeTabs.Trigger>
      <NativeTabs.Trigger name="passes">
        <NativeTabs.Trigger.Label>Passes</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'ticket', selected: 'ticket.fill' }} md="confirmation_number" />
      </NativeTabs.Trigger>
      <NativeTabs.Trigger name="profile">
        <NativeTabs.Trigger.Label>Profile</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'person.crop.circle', selected: 'person.crop.circle.fill' }} md="account_circle" />
      </NativeTabs.Trigger>
    </NativeTabs>
  );
}
