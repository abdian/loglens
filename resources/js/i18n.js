/**
 * LogLens i18n
 * Inline translations for en and fa locales.
 * Loaded from resources/lang/*.json at build time.
 */

const locales = {
  en: {
    'app.title': 'LogLens',
    'files.title': 'Files',
    'files.empty': 'No log files found.',
    'levels.title': 'Levels',
    'groups.title': 'Issues',
    'search.placeholder': 'Search logs… (level:error after:-1h "payment failed")',
    'search.tier': 'Search tier',
    'tail.live': 'Live tail',
    'tail.paused': 'Paused — scroll to bottom to resume',
    'tail.newLines': '{count} new lines',
    'tail.jumpLatest': 'Jump to the latest entries',
    'tail.range': 'Range',
    'entry.raw': 'Raw',
    'entry.parsed': 'Parsed',
    'entry.context': 'Context',
    'entry.trace': 'Stack trace',
    'entry.copy': 'Copy',
    'entry.expand': 'Expand full entry',
    'entry.delete': 'Delete this entry',
    'entry.deleteConfirm': 'Click again to delete',
    'entry.deleted': 'Entry deleted',
    'entry.deleteFailed': 'Could not delete entry',
    'actions.clear': 'Clear',
    'actions.delete': 'Delete',
    'actions.download': 'Download',
    'actions.context': 'View in context',
    'index.building': 'Indexing… {percent}%',
    'index.ready': 'Indexed',
    'index.none': 'Not indexed',
    'diagnostics.title': 'Diagnostics',
    'palette.placeholder': 'Type a command…',
    'theme.dark': 'Dark',
    'theme.light': 'Light',
    'theme.system': 'System',
    'help.title': 'Keyboard shortcuts',
    'help.hint': 'Press ? anytime to toggle this panel.',
    'help.nav': 'Next / previous entry',
    'help.topBottom': 'Jump to top / bottom',
    'help.nextError': 'Next / previous error',
    'help.nextWarn': 'Next / previous warning',
    'help.search': 'Focus search',
    'help.open': 'Open selected entry',
    'help.close': 'Close drawer / clear selection',
    'help.palette': 'Command palette',
    'help.help': 'Toggle this help',
    // Active filters bar
    'filters.active': 'Filters:',
    'filters.removeLevel': 'Remove this level filter',
    'filters.removeTime': 'Remove time filter',
    'filters.removeIssue': 'Remove issue filter',
    'filters.issue': 'Issue',
    'filters.from': 'From',
    'filters.until': 'Until',
    'filters.clearAll': 'Clear all',
    // Issues
    'groups.intro': 'Recurring errors grouped together. Click an issue to see all of its occurrences.',
    'groups.unsupported': 'Error grouping needs the SQLite index (pdo_sqlite), which isn’t available here.',
    'groups.empty': 'No issues yet. Errors and warnings get grouped here as they appear.',
    'groups.sortCount': 'Count',
    'groups.sortRecent': 'Recent',
    'groups.sortFirst': 'First',
    // Histogram
    'histogram.title': 'Volume',
    'histogram.hint': 'Drag to filter by time',
    'histogram.total': 'Total',
    // Detail drawer
    'detail.copyMessage': 'Copy message',
    'detail.copyRaw': 'Copy full entry',
    'detail.download': 'Download this entry',
    'detail.viewContext': 'Show surrounding entries',
    'detail.copyLink': 'Copy permalink',
    'detail.close': 'Close (Esc)',
    'detail.surrounding': 'Surrounding',
    'detail.surroundingHint': 'The entries logged just before and after this one.',
    'detail.jumpToEntry': 'Jump to this entry',
    'detail.thisEntry': 'this entry',
    'detail.noSurrounding': 'No surrounding entries.',
    'detail.mail': 'Mail',
    'detail.contextData': 'Context data',
    'detail.extra': 'Extra',
    'detail.loading': 'Loading…',
    'detail.copyFailed': 'Copy failed',
    'detail.copiedMessage': 'Message copied',
    'detail.copiedRaw': 'Full entry copied',
    'detail.downloaded': 'Entry downloaded',
    'detail.copiedLink': 'Permalink copied',
    // Export current view
    'export.title': 'Export the entries currently shown',
    'export.label': 'Export',
    'export.copy': 'Copy to clipboard',
    'export.download': 'Download .log',
    'export.copied': '{n} entries copied',
    'export.failed': 'Copy failed',
    'export.downloaded': '{n} entries downloaded',
    'export.truncated': '{n} oversized entries were truncated — open them for full content',
    // Index rebuild
    'index.rebuild': 'Reindex',
    'index.rebuildHint': 'Rebuild the search index so search covers timestamps, levels and channels',
    'index.rebuilding': 'Rebuilding search index…',
    'index.rebuildFailed': 'Could not rebuild the index',
    // Search
    'search.hint': 'Search across message, level, time and channel — or refine with level:error, after:-1h, channel:queue.',
    'search.save': 'Save',
    // Confirmation dialog
    'confirm.ok': 'OK',
    'confirm.cancel': 'Cancel',
    // Entry delete
    'entry.deleteTitle': 'Delete this entry?',
    'entry.deleteMessage': 'This soft-deletes the entry from the search index. The original log file on disk is not modified.',
    // File management
    'files.download': 'Download',
    'files.clear': 'Clear',
    'files.delete': 'Delete',
    'files.actionFailed': 'Action failed — it may be disabled by configuration.',
    'files.clearTitle': 'Clear this log file?',
    'files.clearMessage': 'This empties “{name}” — every log entry in it is removed. This cannot be undone.',
    'files.cleared': 'Log file cleared',
    'files.deleteTitle': 'Delete this log file?',
    'files.deleteMessage': 'This permanently deletes “{name}” from disk. This cannot be undone.',
    'files.deleted': 'Log file deleted',
    // Issues tab tooltip
    'groups.tabHint': 'Issues — recurring errors grouped together',
    // Occurrences tab
    'detail.occurrences': 'Occurrences',
    'detail.occurrencesHint': 'Every time this same error has happened (grouped automatically), newest first.',
    'detail.occurrencesCount': 'Seen {n} times',
    'detail.noOccurrences': 'No other occurrences of this error.',
    // Time range
    'time.all': 'All time',
    'time.last15m': 'Last 15 minutes',
    'time.last1h': 'Last hour',
    'time.last6h': 'Last 6 hours',
    'time.last24h': 'Last 24 hours',
    'time.last7d': 'Last 7 days',
    'time.custom': 'Custom range',
    'time.from': 'From',
    'time.to': 'To',
    'time.apply': 'Apply',
    // Loading / empty states
    'common.loading': 'Loading…',
    'search.searching': 'Searching…',
    'search.noResults': 'No matches found.',
    'search.noResultsHint': 'Try broadening your query, or clear the active filters.',
    'search.partial': 'Showing partial results — scroll down to load more.',
    'entries.empty': 'No entries to display.',
    'entries.emptyHint': 'This file has no log entries yet.',
    'entries.selectFile': 'No file selected',
    'entries.selectFileHint': 'Pick a log file from the sidebar to get started.',
    // Detail / sidebar chrome
    'detail.more': 'More actions',
    'detail.resize': 'Drag to resize this panel',
    'sidebar.collapse': 'Collapse sidebar',
    'sidebar.expand': 'Expand sidebar',
    'sidebar.toggle': 'Menu',
    // Histogram granularity
    'histogram.granularity': 'Time grouping',
    'histogram.auto': 'Auto',
    'histogram.hourly': 'Hourly',
    'histogram.daily': 'Daily',
    'histogram.weekly': 'Weekly',
    'histogram.monthly': 'Monthly',
    // Reports / analytics
    'reports.title': 'Reports',
    'reports.total': 'Total entries',
    'reports.errors': 'Errors',
    'reports.warnings': 'Warnings',
    'reports.errorRate': 'Error rate',
    'reports.byLevel': 'Distribution by level',
    'reports.volume': 'Volume over time',
    'reports.topIssues': 'Top recurring errors',
    'reports.filterByLevel': 'Filter the list by this level',
    'reports.partial': 'Counts are still being indexed and may be partial.',
    'reports.empty': 'No data to report yet.',
    // About / links
    'about.title': 'About LogLens',
    'about.github': 'GitHub repository',
    'about.docs': 'Documentation',
    'about.report': 'Report an issue',
    'about.changelog': 'View the changelog',
  },
  fa: {
    'app.title': 'لاگ‌لنز',
    'files.title': 'فایل‌ها',
    'files.empty': 'هیچ فایل لاگی یافت نشد.',
    'levels.title': 'سطوح',
    'groups.title': 'خطاها',
    'search.placeholder': 'جستجوی لاگ‌ها… (level:error after:-1h "payment failed")',
    'search.tier': 'موتور جستجو',
    'tail.live': 'دنبال‌کردن زنده',
    'tail.paused': 'متوقف شد — برای ادامه به پایین بروید',
    'tail.newLines': '{count} خط جدید',
    'tail.jumpLatest': 'پرش به جدیدترین ورودی‌ها',
    'tail.range': 'بازه',
    'entry.raw': 'خام',
    'entry.parsed': 'تجزیه‌شده',
    'entry.context': 'زمینه',
    'entry.trace': 'ردگیری پشته',
    'entry.copy': 'کپی',
    'entry.expand': 'نمایش کامل ورودی',
    'entry.delete': 'حذف این ورودی',
    'entry.deleteConfirm': 'برای حذف دوباره کلیک کنید',
    'entry.deleted': 'ورودی حذف شد',
    'entry.deleteFailed': 'حذف ورودی ناموفق بود',
    'actions.clear': 'پاک‌سازی',
    'actions.delete': 'حذف',
    'actions.download': 'دانلود',
    'actions.context': 'نمایش در متن',
    'index.building': 'در حال نمایه‌سازی… {percent}٪',
    'index.ready': 'نمایه‌سازی شد',
    'index.none': 'نمایه‌سازی نشده',
    'diagnostics.title': 'تشخیص',
    'palette.placeholder': 'یک فرمان تایپ کنید…',
    'theme.dark': 'تیره',
    'theme.light': 'روشن',
    'theme.system': 'سیستم',
    'help.title': 'میان‌برهای صفحه‌کلید',
    'help.hint': 'هر زمان ? را بزنید تا این پنل باز/بسته شود.',
    'help.nav': 'ورودی بعدی / قبلی',
    'help.topBottom': 'پرش به بالا / پایین',
    'help.nextError': 'خطای بعدی / قبلی',
    'help.nextWarn': 'هشدار بعدی / قبلی',
    'help.search': 'تمرکز روی جستجو',
    'help.open': 'باز کردن ورودی انتخاب‌شده',
    'help.close': 'بستن پنل / پاک‌کردن انتخاب',
    'help.palette': 'پالت فرمان',
    'help.help': 'باز/بسته‌کردن همین راهنما',
    // نوار فیلترهای فعال
    'filters.active': 'فیلترها:',
    'filters.removeLevel': 'حذف این فیلتر سطح',
    'filters.removeTime': 'حذف فیلتر زمان',
    'filters.removeIssue': 'حذف فیلتر خطا',
    'filters.issue': 'خطا',
    'filters.from': 'از',
    'filters.until': 'تا',
    'filters.clearAll': 'پاک‌کردن همه',
    // خطاها
    'groups.intro': 'خطاهای تکرارشونده کنار هم گروه‌بندی شده‌اند. روی هر خطا کلیک کنید تا همه‌ی رخدادهای آن را ببینید.',
    'groups.unsupported': 'گروه‌بندی خطاها به نمایه‌ی SQLite (pdo_sqlite) نیاز دارد که اینجا در دسترس نیست.',
    'groups.empty': 'هنوز خطایی نیست. خطاها و هشدارها به‌محض ظاهرشدن اینجا گروه‌بندی می‌شوند.',
    'groups.sortCount': 'تعداد',
    'groups.sortRecent': 'جدیدترین',
    'groups.sortFirst': 'اولین',
    // نمودار
    'histogram.title': 'حجم',
    'histogram.hint': 'برای فیلتر زمانی بکشید',
    'histogram.total': 'مجموع',
    // پنل جزئیات
    'detail.copyMessage': 'کپی پیام',
    'detail.copyRaw': 'کپی کامل ورودی',
    'detail.download': 'دانلود این ورودی',
    'detail.viewContext': 'نمایش ورودی‌های اطراف',
    'detail.copyLink': 'کپی پیوند دائمی',
    'detail.close': 'بستن (Esc)',
    'detail.surrounding': 'اطراف',
    'detail.surroundingHint': 'ورودی‌هایی که دقیقاً پیش و پس از این ورودی ثبت شده‌اند.',
    'detail.jumpToEntry': 'پرش به این ورودی',
    'detail.thisEntry': 'این ورودی',
    'detail.noSurrounding': 'ورودی اطرافی وجود ندارد.',
    'detail.mail': 'ایمیل',
    'detail.contextData': 'داده‌های زمینه',
    'detail.extra': 'اضافه',
    'detail.loading': 'در حال بارگذاری…',
    'detail.copyFailed': 'کپی ناموفق بود',
    'detail.copiedMessage': 'پیام کپی شد',
    'detail.copiedRaw': 'ورودی کامل کپی شد',
    'detail.downloaded': 'ورودی دانلود شد',
    'detail.copiedLink': 'پیوند دائمی کپی شد',
    // خروجی نمای فعلی
    'export.title': 'خروجی‌گرفتن از ورودی‌های نمایش‌داده‌شده',
    'export.label': 'خروجی',
    'export.copy': 'کپی در کلیپ‌بورد',
    'export.download': 'دانلود .log',
    'export.copied': '{n} ورودی کپی شد',
    'export.failed': 'کپی ناموفق بود',
    'export.downloaded': '{n} ورودی دانلود شد',
    'export.truncated': '{n} ورودی بزرگ کوتاه شدند — برای محتوای کامل آن‌ها را باز کنید',
    // بازسازی نمایه
    'index.rebuild': 'بازنمایه‌سازی',
    'index.rebuildHint': 'بازسازی نمایه‌ی جستجو تا جستجو شامل زمان، سطح و کانال شود',
    'index.rebuilding': 'در حال بازسازی نمایه‌ی جستجو…',
    'index.rebuildFailed': 'بازسازی نمایه ناموفق بود',
    // جستجو
    'search.hint': 'جستجو در پیام، سطح، زمان و کانال — یا دقیق‌تر با level:error و after:-1h و channel:queue.',
    'search.save': 'ذخیره',
    // پنجره‌ی تأیید
    'confirm.ok': 'تأیید',
    'confirm.cancel': 'انصراف',
    // حذف ورودی
    'entry.deleteTitle': 'این ورودی حذف شود؟',
    'entry.deleteMessage': 'این ورودی به‌صورت نرم از نمایه‌ی جستجو حذف می‌شود. فایل لاگ اصلی روی دیسک تغییر نمی‌کند.',
    // مدیریت فایل
    'files.download': 'دانلود',
    'files.clear': 'خالی‌کردن',
    'files.delete': 'حذف',
    'files.actionFailed': 'عملیات ناموفق بود — ممکن است با تنظیمات غیرفعال شده باشد.',
    'files.clearTitle': 'این فایل لاگ خالی شود؟',
    'files.clearMessage': 'این کار «{name}» را خالی می‌کند — همه‌ی ورودی‌های آن حذف می‌شوند. قابل بازگشت نیست.',
    'files.cleared': 'فایل لاگ خالی شد',
    'files.deleteTitle': 'این فایل لاگ حذف شود؟',
    'files.deleteMessage': 'این کار «{name}» را برای همیشه از دیسک حذف می‌کند. قابل بازگشت نیست.',
    'files.deleted': 'فایل لاگ حذف شد',
    // راهنمای تب خطاها
    'groups.tabHint': 'خطاها — خطاهای تکرارشونده‌ی گروه‌بندی‌شده',
    // تب تکرارها
    'detail.occurrences': 'تکرارها',
    'detail.occurrencesHint': 'هر باری که همین خطا رخ داده (به‌صورت خودکار گروه‌بندی شده) — از جدید به قدیم.',
    'detail.occurrencesCount': '{n} بار دیده شده',
    'detail.noOccurrences': 'تکرار دیگری از این خطا وجود ندارد.',
    // بازه‌ی زمانی
    'time.all': 'همه‌ی زمان‌ها',
    'time.last15m': '۱۵ دقیقه‌ی اخیر',
    'time.last1h': 'یک ساعت اخیر',
    'time.last6h': '۶ ساعت اخیر',
    'time.last24h': '۲۴ ساعت اخیر',
    'time.last7d': '۷ روز اخیر',
    'time.custom': 'بازه‌ی دلخواه',
    'time.from': 'از',
    'time.to': 'تا',
    'time.apply': 'اعمال',
    // حالت‌های بارگذاری / خالی
    'common.loading': 'در حال بارگذاری…',
    'search.searching': 'در حال جستجو…',
    'search.noResults': 'نتیجه‌ای یافت نشد.',
    'search.noResultsHint': 'کوئری را بازتر کنید یا فیلترهای فعال را پاک کنید.',
    'search.partial': 'نمایش نتایج جزئی — برای بارگذاری بیشتر به پایین بروید.',
    'entries.empty': 'ورودی‌ای برای نمایش نیست.',
    'entries.emptyHint': 'هنوز ورودی لاگی در این فایل نیست.',
    'entries.selectFile': 'فایلی انتخاب نشده',
    'entries.selectFileHint': 'برای شروع، یک فایل لاگ از نوار کناری انتخاب کنید.',
    // جزئیات / نوار کناری
    'detail.more': 'اقدامات بیشتر',
    'detail.resize': 'برای تغییر اندازه‌ی این پنل بکشید',
    'sidebar.collapse': 'جمع‌کردن نوار کناری',
    'sidebar.expand': 'بازکردن نوار کناری',
    'sidebar.toggle': 'منو',
    // بازه‌بندی نمودار
    'histogram.granularity': 'گروه‌بندی زمانی',
    'histogram.auto': 'خودکار',
    'histogram.hourly': 'ساعتی',
    'histogram.daily': 'روزانه',
    'histogram.weekly': 'هفتگی',
    'histogram.monthly': 'ماهانه',
    // گزارش‌ها / تحلیل
    'reports.title': 'گزارش‌ها',
    'reports.total': 'کل ورودی‌ها',
    'reports.errors': 'خطاها',
    'reports.warnings': 'هشدارها',
    'reports.errorRate': 'نرخ خطا',
    'reports.byLevel': 'توزیع بر اساس سطح',
    'reports.volume': 'حجم در طول زمان',
    'reports.topIssues': 'پرتکرارترین خطاها',
    'reports.filterByLevel': 'فیلتر لیست بر اساس این سطح',
    'reports.partial': 'شمارش‌ها هنوز در حال نمایه‌سازی‌اند و ممکن است ناقص باشند.',
    'reports.empty': 'هنوز داده‌ای برای گزارش نیست.',
    // درباره / پیوندها
    'about.title': 'درباره‌ی لاگ‌لنز',
    'about.github': 'مخزن گیت‌هاب',
    'about.docs': 'مستندات',
    'about.report': 'گزارش مشکل',
    'about.changelog': 'مشاهده‌ی تغییرات',
  },
}

/** Active locale string (set once from boot config) */
let activeLocale = 'en'

/**
 * Set the active locale.
 * @param {string} locale - e.g. 'en' or 'fa'
 */
export function setLocale(locale) {
  activeLocale = locales[locale] ? locale : 'en'
}

/**
 * Translate a key, with optional interpolation.
 * @param {string} key
 * @param {Record<string, string|number>} [params]
 * @returns {string}
 */
export function t(key, params = {}) {
  const dict = locales[activeLocale] ?? locales.en
  let str = dict[key] ?? locales.en[key] ?? key
  for (const [k, v] of Object.entries(params)) {
    str = str.replace(`{${k}}`, String(v))
  }
  return str
}

/**
 * Vue plugin — installs `$t` globally and provides `useI18n`.
 */
export const i18nPlugin = {
  install(app) {
    app.config.globalProperties.$t = t
    app.provide('t', t)
  },
}

export default { setLocale, t, i18nPlugin }
