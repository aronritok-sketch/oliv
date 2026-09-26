/**
 * Olivia Kovács Yoga brand tokens, shared with the website theme (olivia-yoga).
 * The app is light only: the paper background and forest greens are the brand.
 */
import '@/global.css';

export const Colors = {
  forest: '#2B5036',
  moss: '#1B3324',
  sage: '#A9BFA0',
  mist: '#DEE7D6',
  lilac: '#C6A3EE',
  pink: '#FF72B6',
  orchid: '#D92B86',
  peach: '#FF8C42',
  ink: '#12231A',
  paper: '#F3F2EC',
  card: '#FFFFFF',
  line: '#E2E1D8',
  muted: '#5E6B62',
  danger: '#B3261E',
  dangerSoft: '#FBE9E7',
  successSoft: '#E4EFDF',
} as const;

export const Fonts = {
  display: 'Anton_400Regular',
  regular: 'Archivo_400Regular',
  medium: 'Archivo_500Medium',
  semibold: 'Archivo_600SemiBold',
  bold: 'Archivo_700Bold',
} as const;

export const Spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const;

export const Radius = {
  sm: 8,
  md: 14,
  lg: 22,
  pill: 999,
} as const;

export const MaxContentWidth = 640;
