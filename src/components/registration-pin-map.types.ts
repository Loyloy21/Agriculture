export type PinPoint = { latitude: number; longitude: number };
export type PinRegion = PinPoint & { latitudeDelta: number; longitudeDelta: number };

export type RegistrationPinMapProps = {
  /** The DRAFT pin: the exact point the farmer has selected so far (null until they choose one). */
  pin: PinPoint | null;
  /** Where the map first opens. This is a view only, never a selected pin. */
  initialRegion: PinRegion;
  /** Re-centre request (for example a phone-GPS starting point). It moves the view only; it never sets or confirms a pin. */
  focus?: { region: PinRegion; nonce: number } | null;
  /** Called with the exact tapped/dragged coordinate. */
  onPick: (point: PinPoint) => void;
  height: number;
  /** "home" or "farm": used only in accessibility text. */
  pinLabel: string;
  /** True while a finger is on the map, so the parent ScrollView can stop scrolling. */
  onGestureActive?: (active: boolean) => void;
};
