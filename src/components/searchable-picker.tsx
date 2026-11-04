import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, SectionList, StyleSheet, Text, TextInput, View } from 'react-native';
import { Icon, palette, ui, type IconName } from '@/components/farm-ui';

export type PickerItem = { key: string; title: string; subtitle?: string; icon?: IconName; badge?: string };
export type PickerSection = { title: string; data: PickerItem[] };

/**
 * Bottom-sheet selection list with a search bar at the top. The caller filters (getSections receives the typed
 * text), so each field keeps its own matching rules. The list is a virtualized SectionList: long catalogs stay
 * responsive and scroll inside the sheet, above the Android keyboard.
 */
export default function SearchablePicker({ visible, title, searchPlaceholder, emptyText, selectedKey, getSections, onSelect, onClose, searchable = true }: {
  visible: boolean;
  title: string;
  searchPlaceholder: string;
  emptyText: string;
  selectedKey: string | null;
  getSections: (query: string) => PickerSection[];
  onSelect: (key: string) => void;
  onClose: () => void;
  searchable?: boolean;
}) {
  const [query, setQuery] = useState('');
  // Every opening starts with an empty search.
  useEffect(() => { if (visible) setQuery(''); }, [visible]);
  const sections = visible ? getSections(query).filter((section) => section.data.length > 0) : [];
  const resultCount = sections.reduce((count, section) => count + section.data.length, 0);

  return <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
    <KeyboardAvoidingView style={styles.backdrop} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Pressable accessibilityRole="button" accessibilityLabel="Close list" onPress={onClose} style={styles.dismiss} />
      <View style={styles.sheet}>
        <View style={styles.handle} />
        <Text style={styles.title}>{title}</Text>
        {searchable && <View style={styles.search}>
          <Icon name="search" size={20} color={palette.muted} />
          <TextInput accessibilityLabel={searchPlaceholder} value={query} onChangeText={setQuery} placeholder={searchPlaceholder} placeholderTextColor="#9AA08D"
            autoCorrect={false} autoCapitalize="none" returnKeyType="search" style={styles.searchInput} />
          {!!query && <Pressable accessibilityRole="button" accessibilityLabel="Clear search" onPress={() => setQuery('')} style={styles.clear}><Icon name="close" size={18} color={palette.muted} /></Pressable>}
        </View>}
        {searchable && !!query && <Text style={styles.count} accessibilityLiveRegion="polite">{resultCount ? `${resultCount} ${resultCount === 1 ? 'match' : 'matches'}` : ''}</Text>}
        <SectionList
          sections={sections}
          keyExtractor={(item) => item.key}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag"
          stickySectionHeadersEnabled={false}
          initialNumToRender={24}
          style={styles.list}
          renderSectionHeader={({ section }) => section.title ? <Text style={styles.sectionTitle}>{section.title}</Text> : null}
          renderItem={({ item }) => <Pressable accessibilityRole="button" accessibilityState={{ selected: item.key === selectedKey }} accessibilityLabel={item.title + (item.subtitle ? ', ' + item.subtitle : '') + (item.badge ? ', ' + item.badge : '')}
            onPress={() => onSelect(item.key)} style={({ pressed }) => [styles.row, item.key === selectedKey && styles.rowSelected, pressed && { opacity: 0.75 }]}>
            {item.icon && <Icon name={item.icon} size={20} />}
            <View style={styles.rowCopy}>
              <Text style={styles.rowTitle}>{item.title}</Text>
              {!!item.subtitle && <Text style={ui.small}>{item.subtitle}</Text>}
              {!!item.badge && <Text style={styles.badge}>{item.badge}</Text>}
            </View>
            {item.key === selectedKey && <Icon name="check" size={20} />}
          </Pressable>}
          ListEmptyComponent={<View style={styles.empty}><Icon name="search" size={26} color={palette.muted} /><Text style={styles.emptyText}>{emptyText}</Text></View>}
        />
        <Pressable accessibilityRole="button" onPress={onClose} style={styles.cancel}><Text style={styles.cancelText}>Cancel</Text></Pressable>
      </View>
    </KeyboardAvoidingView>
  </Modal>;
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.35)', justifyContent: 'flex-end' },
  dismiss: { flex: 1, minHeight: 40 },
  sheet: { maxHeight: '88%', backgroundColor: palette.paper, borderTopLeftRadius: 24, borderTopRightRadius: 24, paddingHorizontal: 18, paddingTop: 14, paddingBottom: 18 },
  handle: { width: 42, height: 4, borderRadius: 4, backgroundColor: '#CBD3C4', alignSelf: 'center', marginBottom: 12 },
  title: { color: palette.ink, fontSize: 20, fontWeight: '800', marginBottom: 10 },
  search: { minHeight: 46, flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1, borderColor: palette.border, borderRadius: 15, backgroundColor: palette.ivory, paddingHorizontal: 12 },
  searchInput: { flex: 1, minWidth: 0, minHeight: 44, color: palette.ink, fontSize: 16 },
  clear: { minWidth: 40, minHeight: 40, alignItems: 'center', justifyContent: 'center' },
  count: { color: palette.muted, fontSize: 12, marginTop: 6, minHeight: 16 },
  // Shrinks inside the sheet's maxHeight, so long lists scroll instead of overflowing.
  list: { flexGrow: 0, flexShrink: 1, marginTop: 4 },
  sectionTitle: { color: palette.muted, fontSize: 12, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.4, marginTop: 14, marginBottom: 2 },
  row: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 10, borderBottomWidth: 1, borderBottomColor: '#EEF1EB', paddingVertical: 8, paddingHorizontal: 4 },
  rowSelected: { backgroundColor: '#EDF6E8' },
  rowCopy: { flex: 1, minWidth: 0 },
  rowTitle: { color: palette.ink, fontSize: 16 },
  badge: { color: palette.forest, fontSize: 12, fontWeight: '700', marginTop: 2 },
  empty: { alignItems: 'center', gap: 8, paddingVertical: 28 },
  emptyText: { color: palette.muted, fontSize: 14, textAlign: 'center' },
  cancel: { minHeight: 48, alignItems: 'center', justifyContent: 'center', marginTop: 6 },
  cancelText: { color: palette.forest, fontSize: 16, fontWeight: '700' },
});
