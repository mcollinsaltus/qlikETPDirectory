const DEFAULT_QLIK_APP_ID = "edfe9bc5-be35-4bde-b515-e72fe46b5240";
const DEFAULT_QLIK_SHEET_ID = "mGLGNCp";
const directoryDatasetConfig = document.querySelector(".qlik-directory-dashboard[data-app-id], #qlikEmbedContainer[data-app-id]");
const directorySearchParams = new URLSearchParams(window.location.search);
const urlAppId = directorySearchParams.has("directory") ? "" : directorySearchParams.get("appId");
const QLIK_APP_ID = (urlAppId || directoryDatasetConfig?.dataset.appId || DEFAULT_QLIK_APP_ID).trim();
const EMERGENCY_HELP_URL = directoryDatasetConfig?.dataset.emergencyHelpUrl || "emergency-help-resources.json";
const QLIK_SHEET_ID = (directoryDatasetConfig?.dataset.sheetId || DEFAULT_QLIK_SHEET_ID).trim();
const VALUE_NULL_STRINGS = ["-", "tba"];
const NULL_REPLACEMENT = "null";
const FIELD_VALUE_DELIMITER = "|!|";

const tableFields = [
  { name: "Org Name", definition: "Organization", cubeRole: "dimension", suppressNull: true, showFilter: false },
  { name: "Contact Name", definition: "=If(Len(CommunityContactLastName) > 1, CommunityContactFirstName &' '& CommunityContactLastName)", cubeRole: "measure", suppressNull: true, showFilter: false },
  { name: "Mission Statement", definition: "=[Mission Statement]", cubeRole: "measure", suppressNull: false, showFilter: false },
  { name: "County", definition: `=concat(distinct [county],'${FIELD_VALUE_DELIMITER}', county_sort)`, cubeRole: "measure", suppressNull: false, showFilter: true },
  { name: "Sector", definition: "=[OrganizationType]", cubeRole: "measure", suppressNull: false, showFilter: true },
  { name: "Address", definition: "=[address_full]", cubeRole: "measure", suppressNull: false, showFilter: false },
  { name: "Contact Phone", definition: "=[contact_phone]", cubeRole: "measure", suppressNull: false, showFilter: false },
  { name: "Contact Email", definition: "=[PreferredEmail]", cubeRole: "measure", suppressNull: false, showFilter: false },
  { name: "Website", definition: "=if(org_active='True'\r\n\t,if(isnull(Website),'no website provided',Website))", cubeRole: "measure", suppressNull: false, showFilter: false },
  { name: "Age Groups", definition: `=If(count({<dem_age-={'tba'}>} distinct dem_age) > 0,\r\n   concat({<dem_age-={'tba'}>} distinct dem_age,'${FIELD_VALUE_DELIMITER}', dem_age_sort), 'tba')`, cubeRole: "measure", suppressNull: true, showFilter: true },
  { name: "Genders", definition: `=If(count({<dem_sex-={'tba'}>} distinct dem_sex) > 0,\r\n   concat({<dem_sex-={'tba'}>} distinct dem_sex,'${FIELD_VALUE_DELIMITER}', dem_sex), 'tba')`, cubeRole: "measure", suppressNull: true, showFilter: true },
  { name: "State", definition: "=concat(distinct State,', ')", cubeRole: "measure", suppressNull: true, showFilter: false },
  { name: "Primary Services", definition: `=concat(distinct [Primary Service],'${FIELD_VALUE_DELIMITER}', primary_service_sort)`, cubeRole: "measure", suppressNull: true, showFilter: true },
  { name: "Secondary Services", definition: `=concat(distinct aggr(distinct concat(distinct program_sub,'${FIELD_VALUE_DELIMITER}'), Organization, [Primary Service]), '${FIELD_VALUE_DELIMITER}', primary_service_sort)`, cubeRole: "measure", suppressNull: true, showFilter: true },
  { name: "Nationalities", definition: `=If(count({<Nationalities-={'tba'}>} distinct Nationalities) > 0, concat({<Nationalities-={'tba'}>} distinct Nationalities, '${FIELD_VALUE_DELIMITER}', Nationalities), 'tba')`, cubeRole: "measure", suppressNull: false, showFilter: true }
];

const PAGE_SIZE = 60;
const QLIK_SEARCH_FIELD = "Directory Search Text";
const qlikFilterFields = {
  counties: "county",
  mainServices: "Primary Service",
  additionalServices: "program_sub",
  organizationType: "OrganizationType"
};

let organizations = [];
let qlikClient = null;
let qlikClientPromise = null;
let pendingQueryId = 0;
let qlikLoadError = "";

function withTimeout(promise, milliseconds, message) {
  let timeoutId;
  const timeout = new Promise((_, reject) => {
    timeoutId = window.setTimeout(() => reject(new Error(message)), milliseconds);
  });

  return Promise.race([promise, timeout]).finally(() => window.clearTimeout(timeoutId));
}

const filterConfig = [
  { key: "counties", label: "Location", searchPlaceholder: "Search counties" },
  { key: "additionalServices", label: "Services" },
  { key: "populations", label: "Who They Serve" },
  { key: "organizationType", label: "Organization Type" }
];

const quickNeeds = [
  { label: "Emergency help", filters: { additionalServices: ["Temporary Shelter", "Intervention"] }, featured: true },
  { label: "Legal support", filters: { additionalServices: ["Legal", "Victim Advocates"] } },
  { label: "Shelter or housing", filters: { additionalServices: ["Temporary Shelter"] } },
  { label: "Counseling", filters: { additionalServices: ["Counseling", "Aftercare"] } },
  { label: "Prevention training", filters: { mainServices: ["Prevention"], additionalServices: ["Training"] } },
  { label: "Organizations near me", action: "nearby" }
];

const zipToCounty = {
  "37013": "Davidson", "37027": "Williamson", "37040": "Montgomery", "37042": "Montgomery",
  "37064": "Williamson", "37122": "Wilson", "37129": "Rutherford", "37130": "Rutherford",
  "37201": "Davidson", "37203": "Davidson", "37206": "Davidson", "37209": "Davidson",
  "37211": "Davidson", "37214": "Davidson", "37221": "Davidson", "37402": "Hamilton",
  "37405": "Hamilton", "37902": "Knox", "37917": "Knox", "38103": "Shelby",
  "38104": "Shelby", "38116": "Shelby"
};

const countyCenters = {
  Anderson: [36.10, -84.20], Bedford: [35.51, -86.45], Benton: [36.07, -88.07],
  Bledsoe: [35.61, -85.19], Blount: [35.69, -83.92], Bradley: [35.16, -84.86],
  Campbell: [36.40, -84.15], Cannon: [35.81, -86.06], Carroll: [35.99, -88.45],
  Carter: [36.29, -82.13], Cheatham: [36.26, -87.09], Chester: [35.43, -88.64],
  Claiborne: [36.48, -83.66], Clay: [36.55, -85.54], Cocke: [35.94, -83.12],
  Coffee: [35.49, -86.08], Crockett: [35.81, -89.14], Cumberland: [35.95, -85.03],
  Davidson: [36.16, -86.78], Decatur: [35.60, -88.11], Dekalb: [35.98, -85.83],
  Dickson: [36.08, -87.38], Dyer: [36.06, -89.41], Fayette: [35.20, -89.41],
  Fentress: [36.38, -84.94], Franklin: [35.16, -86.10], Gibson: [35.99, -88.93],
  Giles: [35.20, -87.03], Grainger: [36.28, -83.51], Greene: [36.17, -82.85],
  Grundy: [35.39, -85.72], Hamblen: [36.22, -83.27], Hamilton: [35.18, -85.16],
  Hancock: [36.53, -83.22], Hardeman: [35.21, -88.99], Hardin: [35.20, -88.19],
  Hawkins: [36.44, -82.95], Haywood: [35.59, -89.28], Henderson: [35.65, -88.39],
  Henry: [36.34, -88.30], Hickman: [35.80, -87.47], Houston: [36.29, -87.71],
  Humphreys: [36.04, -87.78], Jackson: [36.36, -85.67], Jefferson: [36.05, -83.45],
  Johnson: [36.45, -81.85], Knox: [35.99, -83.94], Lake: [36.34, -89.49],
  Lauderdale: [35.76, -89.63], Lawrence: [35.24, -87.33], Lewis: [35.53, -87.49],
  Lincoln: [35.14, -86.59], Loudon: [35.73, -84.31], Macon: [36.54, -86.00],
  Madison: [35.61, -88.84], Marion: [35.13, -85.62], Marshall: [35.47, -86.76],
  Maury: [35.62, -87.08], Mcminn: [35.43, -84.62], Mcnairy: [35.18, -88.56],
  Meigs: [35.52, -84.79], Monroe: [35.45, -84.25], Montgomery: [36.50, -87.38],
  Moore: [35.28, -86.36], Morgan: [36.14, -84.63], Obion: [36.36, -89.15],
  Overton: [36.34, -85.28], Perry: [35.65, -87.86], Pickett: [36.56, -85.08],
  Polk: [35.14, -84.52], Putnam: [36.13, -85.50], Rhea: [35.60, -84.92],
  Roane: [35.85, -84.52], Robertson: [36.53, -86.87], Rutherford: [35.84, -86.42],
  Scott: [36.43, -84.50], Sequatchie: [35.37, -85.41], Sevier: [35.78, -83.52],
  Shelby: [35.18, -89.89], Smith: [36.25, -85.95], Stewart: [36.50, -87.84],
  Sullivan: [36.52, -82.30], Sumner: [36.47, -86.46], Tipton: [35.50, -89.76],
  Trousdale: [36.39, -86.16], Unicoi: [36.13, -82.43], Union: [36.29, -83.84],
  "Van Buren": [35.70, -85.45], Warren: [35.68, -85.78], Washington: [36.30, -82.50],
  Wayne: [35.24, -87.79], Weakley: [36.28, -88.72], White: [35.93, -85.46],
  Williamson: [35.89, -86.90], Wilson: [36.16, -86.30]
};

const state = {
  search: "",
  view: "cards",
  filtersOpen: false,
  activeQuickNeed: "",
  isLoading: true,
  isQuerying: false,
  userLocation: null,
  printMode: "results",
  visibleCount: PAGE_SIZE,
  totalResults: 0,
  hasMoreResults: false,
  facetOptions: {},
  populationFieldMap: {},
  filters: {
    counties: new Set(),
    mainServices: new Set(),
    additionalServices: new Set(),
    populations: new Set(),
    organizationType: new Set()
  },
  countySearch: ""
};

const els = {
  searchInput: document.getElementById("searchInput"),
  searchButton: document.getElementById("searchButton"),
  toggleFilters: document.getElementById("toggleFilters"),
  filtersPanel: document.getElementById("filtersPanel"),
  filterGroups: document.getElementById("filterGroups"),
  needButtons: document.getElementById("needButtons"),
  emergencyResource: document.getElementById("emergencyResource"),
  resultCount: document.getElementById("resultCount"),
  resultSubtext: document.getElementById("resultSubtext"),
  activeFilters: document.getElementById("activeFilters"),
  resultsGrid: document.getElementById("resultsGrid"),
  mapPanel: document.getElementById("mapPanel"),
  emptyState: document.getElementById("emptyState"),
  loadingState: document.getElementById("loadingState"),
  errorState: document.getElementById("errorState"),
  clearFiltersTop: document.getElementById("clearFiltersTop"),
  clearFiltersEmpty: document.getElementById("clearFiltersEmpty"),
  paginationActions: document.getElementById("paginationActions"),
  loadMoreResults: document.getElementById("loadMoreResults"),
  printResults: document.getElementById("printResults"),
  printMapOnly: document.getElementById("printMapOnly"),
  printMapList: document.getElementById("printMapList"),
  printView: document.getElementById("printView"),
  nearbyModal: document.getElementById("nearbyModal"),
  closeNearby: document.getElementById("closeNearby"),
  useLocation: document.getElementById("useLocation"),
  locationStatus: document.getElementById("locationStatus"),
  nearbyText: document.getElementById("nearbyText"),
  applyNearbyText: document.getElementById("applyNearbyText"),
  nearbyCountySelect: document.getElementById("nearbyCountySelect"),
  applyNearbyCounty: document.getElementById("applyNearbyCounty")
};

let directoryMap = null;
let directoryMarkers = null;
let countyBoundaryLayer = null;
let countyBoundaryCache = null;
let countyBoundaryHitLayer = null;

function asArray(value) {
  if (Array.isArray(value)) return value.filter((item) => item && item !== NULL_REPLACEMENT);
  return value && value !== NULL_REPLACEMENT ? [value] : [];
}

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

const fallbackEmergencyHelp = {
  heading: "Emergency help resources",
  intro: "If anyone is in immediate danger, always call 911 first.",
  sections: [
    {
      title: "Non-state-specific 911 information",
      description: "For immediate danger or an urgent medical, fire, or police emergency, call 911.",
      items: [
        { label: "Phone", text: "911", href: "tel:911" }
      ]
    },
    {
      title: "National Human Trafficking Hotline",
      description: "This provides nationwide support and can connect victims with local services.",
      items: [
        { label: "Phone", text: "1-888-373-7888", href: "tel:18883737888" },
        { label: "Text", text: "Text \"HELP\" or \"INFO\" to 233733 (BEFREE)" },
        { label: "Live Chat", text: "humantraffickinghotline.org", href: "https://humantraffickinghotline.org" }
      ]
    }
  ]
};

function renderEmergencyHelpContent(resource = fallbackEmergencyHelp) {
  const sections = Array.isArray(resource.sections) ? resource.sections : fallbackEmergencyHelp.sections;
  els.emergencyResource.innerHTML = `
    <h2>${escapeHtml(resource.heading || fallbackEmergencyHelp.heading)}</h2>
    <p><span class="emergency-alert">${escapeHtml(resource.intro || fallbackEmergencyHelp.intro)}</span></p>
    ${sections.map((section) => `
      <h3>${escapeHtml(section.title || "")}</h3>
      ${section.description ? `<p>${escapeHtml(section.description)}</p>` : ""}
      ${(Array.isArray(section.items) ? section.items : []).map((item) => {
        const text = escapeHtml(item.text || "");
        const value = item.href
          ? `<a href="${escapeHtml(item.href)}" ${String(item.href).startsWith("http") ? `target="_blank" rel="noopener"` : ""}>${text}</a>`
          : text;
        return `<p><strong>${escapeHtml(item.label || "Resource")}:</strong> ${value}</p>`;
      }).join("")}
    `).join("")}
  `;
}

async function loadEmergencyHelpContent() {
  renderEmergencyHelpContent();

  try {
    const response = await fetch(EMERGENCY_HELP_URL, { cache: "no-store" });
    if (!response.ok) throw new Error(`Unable to load ${EMERGENCY_HELP_URL}`);

    const config = await response.json();
    const matchingResource = config.resources?.[QLIK_APP_ID] || config[QLIK_APP_ID] || config.default;
    renderEmergencyHelpContent(matchingResource || fallbackEmergencyHelp);
  } catch (error) {
    console.warn("Using fallback emergency help content.", error);
  }
}

function getOptions(key) {
  const qlikOptions = state.facetOptions[key];
  if (qlikOptions && qlikOptions.length) return qlikOptions;
  return [...new Set(organizations.flatMap((org) => asArray(org[key])))].sort((a, b) => a.localeCompare(b));
}

function resetVisibleResults() {
  state.visibleCount = PAGE_SIZE;
}

function openNearbyModal() {
  renderNearbyCountyOptions();
  els.nearbyModal.classList.add("active");
}

function closeNearbyModal() {
  els.nearbyModal.classList.remove("active");
}

function applyCountyFilter(county) {
  if (!county) return;
  Object.values(state.filters).forEach((set) => set.clear());
  state.filters.counties.add(county);
  state.activeQuickNeed = "Organizations near me";
  resetVisibleResults();
  closeNearbyModal();
  requestDirectoryPage();
}

function normalizeCountyInput(value) {
  const cleanValue = value.trim();
  if (!cleanValue) return "";
  if (zipToCounty[cleanValue]) return zipToCounty[cleanValue];
  const counties = getOptions("counties");
  return counties.find((county) => county.toLowerCase() === cleanValue.toLowerCase())
    || counties.find((county) => county.toLowerCase().startsWith(cleanValue.toLowerCase()))
    || "";
}

function nearestCounty(latitude, longitude) {
  const availableCounties = new Set(getOptions("counties"));
  let bestCounty = "";
  let bestDistance = Infinity;
  Object.entries(countyCenters).forEach(([county, center]) => {
    if (!availableCounties.has(county)) return;
    const distance = Math.hypot(latitude - center[0], longitude - center[1]);
    if (distance < bestDistance) {
      bestDistance = distance;
      bestCounty = county;
    }
  });
  return bestCounty;
}

function milesBetween(lat1, lon1, lat2, lon2) {
  const earthRadiusMiles = 3958.8;
  const toRadians = (degrees) => degrees * Math.PI / 180;
  const dLat = toRadians(lat2 - lat1);
  const dLon = toRadians(lon2 - lon1);
  const a = Math.sin(dLat / 2) ** 2
    + Math.cos(toRadians(lat1)) * Math.cos(toRadians(lat2)) * Math.sin(dLon / 2) ** 2;
  return earthRadiusMiles * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function nearestServedCountyDistance(org) {
  if (!state.userLocation) return null;
  const distances = asArray(org.counties)
    .map((county) => {
      const center = countyCenters[county];
      if (!center) return null;
      return {
        county,
        miles: milesBetween(state.userLocation.latitude, state.userLocation.longitude, center[0], center[1])
      };
    })
    .filter(Boolean)
    .sort((a, b) => a.miles - b.miles);
  return distances[0] || null;
}

const geocodeCacheKey = "directoryQlikPrototypeGeocodeCache";
const geocodeCache = JSON.parse(localStorage.getItem(geocodeCacheKey) || "{}");
const geocodingInProgress = new Set();
const scheduledGeocodes = new Set();

function saveGeocodeCache() {
  localStorage.setItem(geocodeCacheKey, JSON.stringify(geocodeCache));
}

function addressCacheKey(address) {
  return String(address || "").trim().toLowerCase();
}

function applyCachedGeocode(org) {
  if (!org.address) return;
  const cached = geocodeCache[addressCacheKey(org.address)];
  if (cached) org.geo = cached;
}

async function geocodeAddress(org) {
  if (!org.address || org.geo) return;
  const key = addressCacheKey(org.address);
  if (!key || geocodingInProgress.has(key)) return;
  if (Object.prototype.hasOwnProperty.call(geocodeCache, key)) {
    org.geo = geocodeCache[key];
    return;
  }

  geocodingInProgress.add(key);
  try {
    const response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=us&q=${encodeURIComponent(org.address)}`);
    if (!response.ok) {
      geocodeCache[key] = null;
      saveGeocodeCache();
      return;
    }
    const matches = await response.json();
    if (!matches.length) {
      geocodeCache[key] = null;
      saveGeocodeCache();
      return;
    }
    const geo = {
      latitude: Number(matches[0].lat),
      longitude: Number(matches[0].lon),
      source: "address"
    };
    if (Number.isFinite(geo.latitude) && Number.isFinite(geo.longitude)) {
      org.geo = geo;
      geocodeCache[key] = geo;
      saveGeocodeCache();
    }
  } finally {
    geocodingInProgress.delete(key);
  }
}

function distanceForOrg(org) {
  if (!state.userLocation) return null;
  if (org.geo) {
    return {
      miles: milesBetween(state.userLocation.latitude, state.userLocation.longitude, org.geo.latitude, org.geo.longitude),
      target: "address",
      basis: "based on the organization address"
    };
  }
  const countyDistance = nearestServedCountyDistance(org);
  if (!countyDistance) return null;
  return {
    miles: countyDistance.miles,
    target: countyDistance.county,
    basis: "based on the nearest served county center"
  };
}

function queueAddressDistanceLookups(results) {
  if (!state.userLocation) return;
  results.slice(0, 24).forEach((org, index) => {
    applyCachedGeocode(org);
    if (org.geo || !org.address) return;
    const key = addressCacheKey(org.address);
    if (scheduledGeocodes.has(key) || geocodingInProgress.has(key)) return;
    scheduledGeocodes.add(key);
    window.setTimeout(() => {
      geocodeAddress(org).then(() => updateOrgDistance(org)).finally(() => scheduledGeocodes.delete(key));
    }, index * 350);
  });
}

function updateOrgDistance(org) {
  const distance = distanceForOrg(org);
  if (!distance) return;
  const label = `${Math.max(1, Math.round(distance.miles))} mi to ${distance.target}`;
  document.querySelectorAll(`[data-distance-for="${org.id}"]`).forEach((node) => {
    node.textContent = label;
    node.classList.remove("hidden");
  });
  document.querySelectorAll(`[data-distance-detail-for="${org.id}"]`).forEach((node) => {
    node.textContent = `Approx. distance: ${label} ${distance.basis}`;
    node.classList.remove("hidden");
  });
}

function renderNearbyCountyOptions() {
  const counties = getOptions("counties");
  els.nearbyCountySelect.innerHTML = counties.map((county) => `<option value="${escapeHtml(county)}">${escapeHtml(county)}</option>`).join("");
}

function splitValues(value) {
  if (value === null || value === undefined || value === NULL_REPLACEMENT) return [];
  return String(value)
    .split(FIELD_VALUE_DELIMITER)
    .map((item) => item.trim())
    .filter((item) => item && item !== NULL_REPLACEMENT && !VALUE_NULL_STRINGS.includes(item.toLowerCase()));
}

function firstValue(row, fieldName) {
  const values = splitValues(row[fieldName]);
  return values[0] || "";
}

function normalizeWebsite(value) {
  const website = firstValue(value, "Website");
  if (!website || website.toLowerCase() === "no website provided") return "";
  if (/^https?:\/\//i.test(website)) return website;
  return `https://${website}`;
}

function rowToOrganization(row) {
  const contactName = firstValue(row, "Contact Name");
  const phone = firstValue(row, "Contact Phone");
  const email = firstValue(row, "Contact Email");
  const address = firstValue(row, "Address");
  const stateValue = firstValue(row, "State");
  const populations = [
    ...splitValues(row["Age Groups"]),
    ...splitValues(row["Genders"]),
    ...splitValues(row["Nationalities"])
  ];

  return {
    id: "",
    name: firstValue(row, "Org Name") || "Unnamed Organization",
    website: normalizeWebsite(row),
    counties: splitValues(row["County"]),
    mainServices: splitValues(row["Primary Services"]),
    additionalServices: splitValues(row["Secondary Services"]),
    populations: [...new Set(populations)],
    organizationType: firstValue(row, "Sector") || "Other",
    contact: contactName || "Not listed",
    phone,
    email,
    address,
    geo: null,
    stateValue,
    description: firstValue(row, "Mission Statement") || "No mission statement provided.",
    details: [address, stateValue].filter(Boolean).join(", ")
  };
}

function createCubeDef(definition) {
  return {
    qInterColumnSortOrder: [0],
    qDimensions: definition
      .filter((def) => def.cubeRole === "dimension")
      .map((def) => ({
        qNullSuppression: def.suppressNull,
        qDef: {
          qFieldLabels: [def.name],
          qFieldDefs: [def.definition],
          qSortCriterias: [{
            qSortByAscii: 1,
            qSortByLoadOrder: 0,
            qSortByNumeric: 0,
            qSortByState: 0
          }]
        }
      })),
    qMeasures: definition
      .filter((def) => def.cubeRole === "measure")
      .map((def) => ({
        qNullSuppression: def.suppressNull,
        qDef: { qFieldLabels: [def.name], qDef: def.definition }
      }))
  };
}

function parseQlikCell(cell) {
  if (!cell || cell.qText === null || VALUE_NULL_STRINGS.includes(String(cell.qText).toLowerCase())) {
    return NULL_REPLACEMENT;
  }
  if (typeof cell.qNum === "number" && !Number.isNaN(cell.qNum)) {
    return cell.qNum;
  }
  return cell.qText;
}

function semanticRows(columns, tableData) {
  return tableData.map((row) => {
    const rowValue = {};
    row.forEach((cell, index) => {
      rowValue[columns[index].name] = cell;
    });
    return rowValue;
  });
}

function selectedFilterPayload() {
  return Object.fromEntries(
    Object.entries(state.filters).map(([key, values]) => [key, [...values]])
  );
}

function hasActiveQlikSelections(payload) {
  return Boolean(state.search.trim()) || Object.values(payload).some((values) => values.length);
}

function debounce(fn, delay = 250) {
  let timer = 0;
  return (...args) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => fn(...args), delay);
  };
}

function normalizeOrganizations(rows, startIndex) {
  return rows
    .map(rowToOrganization)
    .filter((org) => org.name && org.name !== "Unnamed Organization")
    .map((org, index) => {
      org.id = `org-${startIndex + index}`;
      org.searchText = buildSearchText(org);
      applyCachedGeocode(org);
      return org;
    });
}

async function waitForQlikEmbed(embedEl) {
  for (let i = 0; i < 40; i++) {
    if (typeof embedEl.getRefApi === "function") return embedEl.getRefApi();
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error("Qlik embed did not become ready.");
}

function getQlikErrorMessage(error) {
  return String(error?.message || error || "");
}

function isRecoverableQlikSessionError(error) {
  const message = getQlikErrorMessage(error).toLowerCase();
  return message.includes("session suspended")
    || message.includes("session closed")
    || message.includes("session timed out");
}

function resetQlikClient() {
  qlikClient = null;
  qlikClientPromise = null;
  const embedEl = document.getElementById("qlikEmbed");
  if (embedEl) embedEl.remove();
}

async function initializeQlikClient() {
  if (qlikClient) return qlikClient;
  if (qlikClientPromise) return qlikClientPromise;

  qlikClientPromise = (async () => {
    const container = document.getElementById("qlikEmbedContainer");
    if (!container) throw new Error("Directory embed container is missing.");
    if (!QLIK_APP_ID) throw new Error("Directory app id is missing.");

    let embedEl = document.getElementById("qlikEmbed");
    if (!embedEl) {
      embedEl = document.createElement("qlik-embed");
      embedEl.id = "qlikEmbed";
      embedEl.setAttribute("ui", "analytics/selections");
      embedEl.setAttribute("app-id", QLIK_APP_ID);
      embedEl.setAttribute("sheet-id", QLIK_SHEET_ID);
      container.appendChild(embedEl);
    }

    const refApi = await waitForQlikEmbed(embedEl);
    const doc = await withTimeout(refApi.getDoc(), 20000, "Qlik document connection timed out.");
    const tableDefinition = {
      qInfo: { qType: "sn-table" },
      qHyperCubeDef: createCubeDef(tableFields)
    };
    const table = await withTimeout(doc.createSessionObject(tableDefinition), 20000, "Qlik directory table setup timed out.");
    const tableProperties = await withTimeout(table.getProperties(), 20000, "Qlik directory table properties timed out.");
    const columns = [
      ...tableProperties.qHyperCubeDef.qDimensions,
      ...tableProperties.qHyperCubeDef.qMeasures
    ].map((column) => ({ name: column.qDef.qLabel || column.qDef.qFieldLabels[0] }));

    qlikClient = { doc, table, columns };
    return qlikClient;
  })();

  try {
    return await qlikClientPromise;
  } catch (error) {
    resetQlikClient();
    throw error;
  }
}

async function trySelectField(doc, fieldName, values) {
  try {
    if (!fieldName || !values.length || typeof doc.createSessionObject !== "function") return;
    const listObject = await doc.createSessionObject({
      qInfo: { qType: "directory-filter-list" },
      qListObjectDef: {
        qDef: { qFieldDefs: [fieldName] },
        qShowAlternatives: true,
        qInitialDataFetch: [{ qTop: 0, qLeft: 0, qWidth: 1, qHeight: 5000 }]
      }
    });
    const layout = await listObject.getLayout();
    const wantedValues = new Set(values.map((value) => String(value).toLowerCase()));
    const elementNumbers = (layout.qListObject.qDataPages?.[0]?.qMatrix || [])
      .filter((row) => wantedValues.has(String(row[0]?.qText || "").toLowerCase()))
      .map((row) => row[0]?.qElemNumber)
      .filter((value) => Number.isInteger(value) && value >= 0);

    if (elementNumbers.length && typeof listObject.selectListObjectValues === "function") {
      await listObject.selectListObjectValues("/qListObjectDef", elementNumbers, false);
    }
  } catch (error) {
    return;
  }
}

async function trySearchField(doc, fieldName, searchText) {
  const cleanSearch = searchText.trim();
  if (!fieldName || !cleanSearch || typeof doc.createSessionObject !== "function") return;

  try {
    const searchList = await doc.createSessionObject({
      qInfo: { qType: "directory-search-list" },
      qListObjectDef: {
        qDef: { qFieldDefs: [fieldName] },
        qShowAlternatives: true,
        qInitialDataFetch: [{ qTop: 0, qLeft: 0, qWidth: 1, qHeight: 1000 }]
      }
    });

    if (typeof searchList.searchListObjectFor === "function"
      && typeof searchList.acceptListObjectSearch === "function") {
      await searchList.searchListObjectFor("/qListObjectDef", cleanSearch);
      await searchList.acceptListObjectSearch("/qListObjectDef", false);
      return;
    }

    if (typeof searchList.searchListObjectFor === "function"
      && typeof searchList.selectListObjectValues === "function") {
      await searchList.searchListObjectFor("/qListObjectDef", cleanSearch);
      const layout = await searchList.getLayout();
      const matches = layout.qListObject.qDataPages?.[0]?.qMatrix || [];
      const elementNumbers = matches
        .map((row) => row[0]?.qElemNumber)
        .filter((value) => Number.isInteger(value) && value >= 0);
      if (elementNumbers.length) {
        await searchList.selectListObjectValues("/qListObjectDef", elementNumbers, false);
      }
    }
  } catch (error) {
    return;
  }
}

async function requestQlikListValues(doc, fieldName, maxRows = 1000) {
  const listObject = await doc.createSessionObject({
    qInfo: { qType: "directory-facet-list" },
    qListObjectDef: {
      qDef: { qFieldDefs: [fieldName] },
      qShowAlternatives: true,
      qInitialDataFetch: [{ qTop: 0, qLeft: 0, qWidth: 1, qHeight: maxRows }]
    }
  });
  const layout = await listObject.getLayout();
  const rows = layout.qListObject.qDataPages?.[0]?.qMatrix || [];
  return rows
    .map((row) => row[0]?.qText)
    .filter((value) => value && !VALUE_NULL_STRINGS.includes(String(value).toLowerCase()))
    .sort((a, b) => a.localeCompare(b));
}

async function loadQlikFacetOptions(doc) {
  const facetRequests = [
    ["counties", "county"],
    ["mainServices", "Primary Service"],
    ["additionalServices", "program_sub"],
    ["organizationType", "OrganizationType"],
    ["ageGroups", "dem_age"],
    ["genders", "dem_sex"],
    ["nationalities", "Nationalities"]
  ];
  const settled = await Promise.allSettled(
    facetRequests.map(async ([key, field]) => [key, await requestQlikListValues(doc, field)])
  );
  const nextOptions = {};
  settled.forEach((result) => {
    if (result.status === "fulfilled") nextOptions[result.value[0]] = result.value[1];
  });
  nextOptions.populations = [
    ...(nextOptions.ageGroups || []),
    ...(nextOptions.genders || []),
    ...(nextOptions.nationalities || [])
  ].filter((value, index, values) => values.indexOf(value) === index)
    .sort((a, b) => a.localeCompare(b));
  state.populationFieldMap = {};
  (nextOptions.ageGroups || []).forEach((value) => { state.populationFieldMap[value] = "dem_age"; });
  (nextOptions.genders || []).forEach((value) => { state.populationFieldMap[value] = "dem_sex"; });
  (nextOptions.nationalities || []).forEach((value) => { state.populationFieldMap[value] = "Nationalities"; });
  delete nextOptions.ageGroups;
  delete nextOptions.genders;
  delete nextOptions.nationalities;
  state.facetOptions = nextOptions;
}

async function applyQlikSelections(doc, filters, search) {
  if (typeof doc.clearAll === "function") {
    try {
      await doc.clearAll();
    } catch (error) {
      return;
    }
  }

  for (const [key, values] of Object.entries(filters)) {
    if (key === "populations") {
      const byField = values.reduce((acc, value) => {
        const field = state.populationFieldMap[value];
        if (!field) return acc;
        if (!acc[field]) acc[field] = [];
        acc[field].push(value);
        return acc;
      }, {});
      for (const [field, fieldValues] of Object.entries(byField)) {
        await trySelectField(doc, field, fieldValues);
      }
    } else {
      await trySelectField(doc, qlikFilterFields[key], values);
    }
  }

  const cleanSearch = search.trim();
  if (cleanSearch) {
    await trySearchField(doc, QLIK_SEARCH_FIELD, cleanSearch);
  }
}

async function queryDirectoryPage({ append }) {
  const client = await initializeQlikClient();
  const filters = selectedFilterPayload();
  if (Object.keys(state.facetOptions).length === 0) {
    await loadQlikFacetOptions(client.doc);
  }
  await applyQlikSelections(client.doc, filters, state.search);

  const tableLayout = await withTimeout(client.table.getLayout(), 20000, "Qlik directory layout timed out.");
  const totalRows = tableLayout.qHyperCube.qSize.qcy;
  const start = append ? organizations.length : 0;
  const data = await withTimeout(client.table.getHyperCubeData("/qHyperCubeDef", [{
    qLeft: 0,
    qTop: start,
    qWidth: tableFields.length,
    qHeight: PAGE_SIZE
  }]), 20000, "Qlik directory data request timed out.");

  return { client, data, filters, start, totalRows };
}

async function requestDirectoryPage({ append = false } = {}) {
  const queryId = ++pendingQueryId;
  state.isQuerying = true;
  qlikLoadError = "";
  render({ filters: false, results: false });

  try {
    let result;
    try {
      result = await queryDirectoryPage({ append });
    } catch (error) {
      if (!isRecoverableQlikSessionError(error)) throw error;
      resetQlikClient();
      result = await queryDirectoryPage({ append });
    }

    if (queryId !== pendingQueryId) return;

    const { client, data, filters, start, totalRows } = result;
    const tableData = data.flatMap((page) => page.qMatrix).map((row) => row.map(parseQlikCell));
    const pageRows = normalizeOrganizations(semanticRows(client.columns, tableData), start);
    organizations = append ? [...organizations, ...pageRows] : pageRows;
    state.totalResults = totalRows;
    state.visibleCount = organizations.length;
    state.hasMoreResults = organizations.length < totalRows;

    if (!hasActiveQlikSelections(filters) && Object.keys(state.facetOptions).length === 0) {
      state.facetOptions = Object.fromEntries(filterConfig.map((group) => [group.key, getOptions(group.key)]));
    }
  } catch (error) {
    if (isRecoverableQlikSessionError(error)) resetQlikClient();
    qlikLoadError = getQlikErrorMessage(error) || "Unable to load Qlik data.";
  } finally {
    if (queryId !== pendingQueryId) return;
    state.isLoading = false;
    state.isQuerying = false;
    render();
  }
}

function loadQlikOrganizations() {
  requestDirectoryPage();
}

function toggleFilter(key, value) {
  const set = state.filters[key];
  if (set.has(value)) return;
  set.add(value);
  state.activeQuickNeed = "";
  resetVisibleResults();
  requestDirectoryPage();
}

function clearFilters() {
  Object.values(state.filters).forEach((set) => set.clear());
  state.search = "";
  state.countySearch = "";
  state.activeQuickNeed = "";
  resetVisibleResults();
  els.searchInput.value = "";
  requestDirectoryPage();
}

function applyQuickNeed(need) {
  Object.values(state.filters).forEach((set) => set.clear());
  Object.entries(need.filters).forEach(([key, values]) => {
    values.forEach((value) => state.filters[key].add(value));
  });
  state.activeQuickNeed = need.label;
  resetVisibleResults();
  requestDirectoryPage();
}

function orgMatchesFilters(org) {
  return Object.entries(state.filters).every(([key, selected]) => {
    if (selected.size === 0) return true;
    const values = asArray(org[key]);
    return [...selected].some((value) => values.includes(value));
  });
}

function compareOrganizationsByName(a, b) {
  return a.name.localeCompare(b.name, undefined, { sensitivity: "base", numeric: true });
}

function buildSearchText(org) {
  return [
    org.name,
    org.contact,
    org.description,
    org.details,
    org.organizationType,
    org.address,
    org.stateValue,
    ...org.counties,
    ...org.mainServices,
    ...org.additionalServices,
    ...org.populations
  ].join(" ").toLowerCase();
}

function getFilteredOrganizations() {
  return organizations
    .sort(compareOrganizationsByName);
}

function summarizeList(values, maxVisible = 4, emptyText = "Not listed") {
  const cleanValues = asArray(values);
  if (cleanValues.length === 0) return emptyText;
  if (cleanValues.length <= maxVisible) return cleanValues.join(", ");
  return `${cleanValues.slice(0, maxVisible).join(", ")} + ${cleanValues.length - maxVisible} more`;
}

function renderQuickNeeds() {
  els.needButtons.innerHTML = quickNeeds.map((need) => `
    <button class="need ${need.featured ? "featured" : ""} ${state.activeQuickNeed === need.label ? "active" : ""}" data-need="${need.label}">
      ${need.label}
    </button>
  `).join("");

  els.emergencyResource.classList.toggle("active", state.activeQuickNeed === "Emergency help");

  els.needButtons.querySelectorAll("[data-need]").forEach((button) => {
    button.addEventListener("click", () => {
      const need = quickNeeds.find((item) => item.label === button.dataset.need);
      if (need.action === "nearby") {
        openNearbyModal();
      } else {
        applyQuickNeed(need);
      }
    });
  });
}

function renderFilters() {
  els.filtersPanel.classList.toggle("collapsed", !state.filtersOpen);
  els.filtersPanel.parentElement.classList.toggle("filters-hidden", !state.filtersOpen);
  els.toggleFilters.textContent = state.filtersOpen ? "Hide Filters" : "Open Filters";

  els.filterGroups.innerHTML = filterConfig.map((group) => {
    const isCountyGroup = group.key === "counties";
    const options = getOptions(group.key).filter((option) => {
      if (!isCountyGroup || !state.countySearch) return true;
      return option.toLowerCase().includes(state.countySearch.toLowerCase());
    });
    const search = group.searchPlaceholder
      ? `<input class="filter-search" id="countySearch" placeholder="${group.searchPlaceholder}" value="${state.countySearch}">`
      : "";
    const hint = isCountyGroup
      ? `<p class="filter-hint">${options.length} counties shown. Use search to narrow this list.</p>`
      : "";

    return `
      <div class="filter-group">
        <p class="filter-title">${group.label}</p>
        ${search}
        ${hint}
        <div class="chip-row ${isCountyGroup ? "scroll-list" : ""}">
          ${options.map((option) => `
            <button class="chip ${state.filters[group.key].has(option) ? "selected" : ""}"
              aria-pressed="${state.filters[group.key].has(option)}"
              data-filter-key="${group.key}"
              data-filter-value="${escapeHtml(option)}">
              ${escapeHtml(option)}
            </button>
          `).join("")}
        </div>
      </div>
    `;
  }).join("");

  const countySearch = document.getElementById("countySearch");
  if (countySearch) {
    countySearch.addEventListener("input", (event) => {
      state.countySearch = event.target.value;
      renderFilters();
    });
  }

  els.filterGroups.querySelectorAll("[data-filter-key]").forEach((button) => {
    button.addEventListener("click", () => toggleFilter(button.dataset.filterKey, button.dataset.filterValue));
  });
}

function renderActiveFilters() {
  const chips = [];
  Object.entries(state.filters).forEach(([key, values]) => {
    values.forEach((value) => chips.push({ key, value }));
  });

  if (state.search.trim()) {
    chips.unshift({ key: "search", value: `Search: ${state.search.trim()}` });
  }

  els.activeFilters.innerHTML = chips.map((chip) => `
    <button class="active-chip" data-active-key="${chip.key}" data-active-value="${escapeHtml(chip.value)}">
      ${escapeHtml(chip.value)} x
    </button>
  `).join("");

  els.activeFilters.querySelectorAll("[data-active-key]").forEach((button) => {
    button.addEventListener("click", () => {
      if (button.dataset.activeKey === "search") {
        state.search = "";
        els.searchInput.value = "";
      } else {
        state.filters[button.dataset.activeKey].delete(button.dataset.activeValue);
      }
      state.activeQuickNeed = "";
      resetVisibleResults();
      requestDirectoryPage();
    });
  });
}

function renderCards(results) {
  els.resultsGrid.innerHTML = results.map((org, index) => {
    const countySummary = summarizeList(org.counties, 4, "County not listed");
    const allCounties = summarizeList(org.counties, 999, "County not listed");
    applyCachedGeocode(org);
    const distance = distanceForOrg(org);
    const distanceLabel = distance
      ? `${Math.max(1, Math.round(distance.miles))} mi to ${distance.target}`
      : "";
    return `
    <article class="org-card">
      <div class="card-top">
        <h3>${escapeHtml(org.name)}</h3>
        ${org.website ? `<a class="website-link" href="${escapeHtml(org.website)}" target="_blank" rel="noreferrer">Website -></a>` : `<span class="website-link">No website</span>`}
      </div>
      <div class="card-body">
        <div class="meta">
          <span class="county-summary"><strong>Serves:</strong> ${escapeHtml(countySummary)}</span>
          <span class="distance-pill ${distanceLabel ? "" : "hidden"}" data-distance-for="${escapeHtml(org.id)}">${escapeHtml(distanceLabel)}</span>
          <span><strong>Contact person:</strong> ${escapeHtml(org.contact || "Not listed")}</span>
          <span><strong>Phone:</strong> ${escapeHtml(org.phone || "Not listed")}</span>
          <span><strong>Type:</strong> ${escapeHtml(org.organizationType)}</span>
        </div>
        <div class="service-list">
          ${org.mainServices.map((service) => `<button class="service-tag main" data-tag-key="mainServices" data-tag-value="${escapeHtml(service)}">${escapeHtml(service)}</button>`).join("")}
          ${org.additionalServices.slice(0, 3).map((service) => `<button class="service-tag" data-tag-key="additionalServices" data-tag-value="${escapeHtml(service)}">${escapeHtml(service)}</button>`).join("")}
        </div>
        <p class="description">${escapeHtml(org.description)}</p>
        <div class="details-panel" id="details-${index}">
          <strong>Counties served:</strong> ${escapeHtml(allCounties)}<br>
          <span class="${distanceLabel ? "" : "hidden"}" data-distance-detail-for="${escapeHtml(org.id)}">${distanceLabel ? `Approx. distance: ${escapeHtml(distanceLabel)} ${escapeHtml(distance.basis)}` : ""}</span>${distanceLabel ? "<br>" : ""}
          <strong>Address:</strong> ${escapeHtml(org.address || org.details || "No address listed.")}<br>
          <strong>Who they serve:</strong> ${escapeHtml(org.populations.join(", ") || "Not listed")}
        </div>
        <div class="card-actions">
          <button class="button primary" data-call="${escapeHtml(org.phone)}" ${org.phone ? "" : "disabled"}>Call</button>
          <button class="button" data-email="${escapeHtml(org.email)}" ${org.email ? "" : "disabled"}>Email</button>
          <button class="button details" data-details="details-${index}">View Details</button>
        </div>
      </div>
    </article>
  `;
  }).join("");

  els.resultsGrid.classList.toggle("list-view", state.view === "list");

  els.resultsGrid.querySelectorAll("[data-tag-key]").forEach((button) => {
    button.addEventListener("click", () => toggleFilter(button.dataset.tagKey, button.dataset.tagValue));
  });

  els.resultsGrid.querySelectorAll("[data-details]").forEach((button) => {
    button.addEventListener("click", () => {
      const panel = document.getElementById(button.dataset.details);
      panel.classList.toggle("open");
      button.textContent = panel.classList.contains("open") ? "Hide Details" : "View Details";
    });
  });

  els.resultsGrid.querySelectorAll("[data-call]").forEach((button) => {
    button.addEventListener("click", () => {
      window.location.href = `tel:${button.dataset.call}`;
    });
  });

  els.resultsGrid.querySelectorAll("[data-email]").forEach((button) => {
    button.addEventListener("click", () => {
      window.location.href = `mailto:${button.dataset.email}`;
    });
  });
}

function fallbackMap(results) {
  const positions = [
    [8, 14], [54, 18], [30, 38], [68, 48], [16, 68], [48, 72], [78, 76], [38, 12]
  ];
  els.mapPanel.innerHTML = `<div class="map-fallback">${
    results.slice(0, 8).map((org, index) => {
    const [left, top] = positions[index];
    return `<div class="map-pin" style="left:${left}%;top:${top}%">${escapeHtml(org.counties[0] || "Location")}<br>${escapeHtml(org.name)}</div>`;
  }).join("")
  }</div><div class="map-notice">Map tiles could not load, so this fallback shows a simplified location preview.</div>`;
}

function mapLocationForOrg(org, index) {
  if (org.geo) {
    return {
      lat: org.geo.latitude,
      lng: org.geo.longitude,
      label: "Organization address",
      basis: org.address || "Address"
    };
  }
  const county = asArray(org.counties).find((item) => countyCenters[item]);
  if (!county) return null;
  const [lat, lng] = countyCenters[county];
  const angle = (index % 24) * (Math.PI * 2 / 24);
  const distance = 0.025 + ((index % 5) * 0.006);
  return {
    lat: lat + Math.sin(angle) * distance,
    lng: lng + Math.cos(angle) * distance,
    label: county,
    basis: "Served county center"
  };
}

function selectedMapCounties(results) {
  if (state.filters.counties.size > 0) return [...state.filters.counties];
  return [];
}

function countyBounds(county) {
  const center = countyCenters[county];
  if (!center) return null;
  const [lat, lng] = center;
  return [[lat - 0.18, lng - 0.24], [lat + 0.18, lng + 0.24]];
}

function renderApproxCountyBorders(counties) {
  countyBoundaryLayer = L.layerGroup().addTo(directoryMap);
  countyBoundaryHitLayer = L.layerGroup().addTo(directoryMap);
  counties.forEach((county) => {
    const bounds = countyBounds(county);
    if (!bounds) return;
    L.rectangle(bounds, {
      color: "#7c2dff",
      weight: 3,
      fillColor: "#7c2dff",
      fillOpacity: 0.08,
      interactive: false
    }).addTo(countyBoundaryLayer);
    L.rectangle(bounds, {
      color: "#7c2dff",
      weight: 12,
      fill: false,
      opacity: 0,
      interactive: true
    })
      .bindTooltip(`${escapeHtml(county)} County`, {
        direction: "center",
        sticky: true,
        opacity: 1
      })
      .addTo(countyBoundaryLayer);
  });
  bringOrgMarkersToFront();
}

async function renderCountyBorders(counties) {
  if (!directoryMap || !counties.length) return;
  if (countyBoundaryLayer) {
    countyBoundaryLayer.remove();
    countyBoundaryLayer = null;
  }
  if (countyBoundaryHitLayer) {
    countyBoundaryHitLayer.remove();
    countyBoundaryHitLayer = null;
  }
  try {
    if (!countyBoundaryCache) {
      const response = await fetch("https://raw.githubusercontent.com/plotly/datasets/master/geojson-counties-fips.json");
      if (!response.ok) throw new Error("County border data unavailable");
      countyBoundaryCache = await response.json();
    }
    const selected = new Set(counties.map((county) => county.toLowerCase()));
    const features = countyBoundaryCache.features.filter((feature) => {
      const id = String(feature.id || "");
      const name = String(feature.properties?.NAME || feature.properties?.name || "").toLowerCase();
      return id.startsWith("47") && selected.has(name);
    });
    if (!features.length) {
      renderApproxCountyBorders(counties);
      return;
    }
    countyBoundaryLayer = L.geoJSON(features, {
      style: {
        color: "#7c2dff",
        weight: 3,
        fillColor: "#7c2dff",
        fillOpacity: 0.08
      },
      interactive: false
    }).addTo(directoryMap);
    countyBoundaryHitLayer = L.geoJSON(features, {
      style: {
        color: "#7c2dff",
        weight: 14,
        opacity: 0,
        fill: false,
        fillOpacity: 0
      },
      interactive: true,
      onEachFeature: (feature, layer) => {
        const countyName = feature.properties?.NAME || feature.properties?.name || "Selected";
        layer.bindTooltip(`${escapeHtml(countyName)} County`, {
          direction: "center",
          sticky: true,
          opacity: 1
        });
      }
    }).addTo(directoryMap);
    bringOrgMarkersToFront();
  } catch (error) {
    renderApproxCountyBorders(counties);
  }
}

function bringOrgMarkersToFront() {
  if (!directoryMarkers) return;
  directoryMarkers.eachLayer((layer) => {
    if (typeof layer.bringToFront === "function") layer.bringToFront();
    if (typeof layer.setZIndexOffset === "function") layer.setZIndexOffset(1000);
  });
}

function printableMapData(results) {
  return results
    .map((org, index) => ({ number: index + 1, org, location: mapLocationForOrg(org, index) }))
    .filter((item) => item.location)
    .slice(0, 250);
}

function projectPrintPoint(location, bounds, width, height) {
  if (bounds.scale && bounds.originPixelX !== undefined && bounds.originPixelY !== undefined) {
    const world = projectTilePixel(location.lat, location.lng, bounds.zoom);
    return {
      x: Math.min(width - 14, Math.max(14, (world.x - bounds.originPixelX) * bounds.scale)),
      y: Math.min(height - 14, Math.max(14, (world.y - bounds.originPixelY) * bounds.scale))
    };
  }
  const x = ((location.lng - bounds.minLng) / Math.max(0.01, bounds.maxLng - bounds.minLng)) * width;
  const y = (1 - ((location.lat - bounds.minLat) / Math.max(0.01, bounds.maxLat - bounds.minLat))) * height;
  return {
    x: Math.min(width - 14, Math.max(14, x)),
    y: Math.min(height - 14, Math.max(14, y))
  };
}

function projectTilePixel(lat, lng, zoom) {
  const tileSize = 256;
  const scale = tileSize * (2 ** zoom);
  const clampedLat = Math.min(85.0511, Math.max(-85.0511, lat));
  const latRad = clampedLat * Math.PI / 180;
  return {
    x: ((lng + 180) / 360) * scale,
    y: (1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2 * scale
  };
}

function printMapSvg(mapRows, counties) {
  const width = 760;
  const height = 430;
  const locations = mapRows.map((row) => row.location);
  counties.forEach((county) => {
    const center = countyCenters[county];
    if (center) locations.push({ lat: center[0], lng: center[1] });
  });
  const lats = locations.map((item) => item.lat);
  const lngs = locations.map((item) => item.lng);
  const geoBounds = {
    minLat: Math.min(...lats, 34.9) - 0.25,
    maxLat: Math.max(...lats, 36.8) + 0.25,
    minLng: Math.min(...lngs, -90.4) - 0.35,
    maxLng: Math.max(...lngs, -81.5) + 0.35
  };
  const lngSpan = geoBounds.maxLng - geoBounds.minLng;
  const latSpan = geoBounds.maxLat - geoBounds.minLat;
  const zoom = lngSpan > 12 || latSpan > 5 ? 7 : lngSpan < 4 && latSpan < 2.5 ? 9 : 8;
  const topLeft = projectTilePixel(geoBounds.maxLat, geoBounds.minLng, zoom);
  const bottomRight = projectTilePixel(geoBounds.minLat, geoBounds.maxLng, zoom);
  const spanX = Math.max(1, bottomRight.x - topLeft.x);
  const spanY = Math.max(1, bottomRight.y - topLeft.y);
  const mapScale = Math.min(width / spanX, height / spanY);
  const visibleWorldWidth = width / mapScale;
  const visibleWorldHeight = height / mapScale;
  const bounds = {
    ...geoBounds,
    zoom,
    scale: mapScale,
    originPixelX: topLeft.x - Math.max(0, visibleWorldWidth - spanX) / 2,
    originPixelY: topLeft.y - Math.max(0, visibleWorldHeight - spanY) / 2
  };
  const tileSize = 256;
  const tileCount = 2 ** zoom;
  const minTileX = Math.floor(bounds.originPixelX / tileSize);
  const maxTileX = Math.floor((bounds.originPixelX + visibleWorldWidth) / tileSize);
  const minTileY = Math.max(0, Math.floor(bounds.originPixelY / tileSize));
  const maxTileY = Math.min(tileCount - 1, Math.floor((bounds.originPixelY + visibleWorldHeight) / tileSize));
  const tileImages = [];
  for (let tileY = minTileY; tileY <= maxTileY; tileY += 1) {
    for (let tileX = minTileX; tileX <= maxTileX; tileX += 1) {
      const wrappedTileX = ((tileX % tileCount) + tileCount) % tileCount;
      const x = (tileX * tileSize - bounds.originPixelX) * mapScale;
      const y = (tileY * tileSize - bounds.originPixelY) * mapScale;
      const size = tileSize * mapScale;
      tileImages.push(`<image href="https://tile.openstreetmap.org/${zoom}/${wrappedTileX}/${tileY}.png" x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${size.toFixed(1)}" height="${size.toFixed(1)}" preserveAspectRatio="none"></image>`);
    }
  }
  const countyShapes = counties.map((county) => {
    const center = countyCenters[county];
    if (!center) return "";
    const cornerA = projectPrintPoint({ lat: center[0] - 0.18, lng: center[1] - 0.24 }, bounds, width, height);
    const cornerB = projectPrintPoint({ lat: center[0] + 0.18, lng: center[1] + 0.24 }, bounds, width, height);
    const x = Math.min(cornerA.x, cornerB.x);
    const y = Math.min(cornerA.y, cornerB.y);
    const w = Math.abs(cornerB.x - cornerA.x);
    const h = Math.abs(cornerB.y - cornerA.y);
    return `<rect x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${w.toFixed(1)}" height="${h.toFixed(1)}" rx="5" fill="#efe7ff" stroke="#7c2dff" stroke-width="3"><title>${escapeHtml(county)} County filter area</title></rect>`;
  }).join("");
  const points = mapRows.map((row) => {
    const point = projectPrintPoint(row.location, bounds, width, height);
    const fill = row.org.geo ? "#087f83" : "#276fbf";
    return `<g><circle cx="${point.x.toFixed(1)}" cy="${point.y.toFixed(1)}" r="9" fill="${fill}" stroke="#fff" stroke-width="2"></circle><text x="${point.x.toFixed(1)}" y="${(point.y + 4).toFixed(1)}" text-anchor="middle" font-size="9" fill="#fff" font-weight="700">${row.number}</text></g>`;
  }).join("");
  return `<svg class="print-map-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Printable map of organization locations">
    <rect width="${width}" height="${height}" fill="#eef5f6"></rect>
    <g>${tileImages.join("")}</g>
    <rect width="${width}" height="${height}" fill="#ffffff" opacity=".18"></rect>
    ${countyShapes}
    ${points}
    <text x="${(width - 8).toFixed(1)}" y="${(height - 8).toFixed(1)}" text-anchor="end" font-size="8" fill="#42525a">Map data © OpenStreetMap contributors</text>
  </svg>`;
}

function mapCardHtml(org, location) {
  const services = [...org.mainServices, ...org.additionalServices]
    .slice(0, 5)
    .map((service) => `<span>${escapeHtml(service)}</span>`)
    .join("");
  const description = org.description ? `${escapeHtml(org.description).slice(0, 190)}${org.description.length > 190 ? "..." : ""}` : "";
  return `
    <article class="map-card">
      <h3>${escapeHtml(org.name)}</h3>
      <p><strong>Location:</strong> ${escapeHtml(location.label)} <span>(${escapeHtml(location.basis)})</span></p>
      <p><strong>Serves:</strong> ${escapeHtml(summarizeList(org.counties, 4, "County not listed"))}</p>
      ${org.address ? `<p><strong>Address:</strong> ${escapeHtml(org.address)}</p>` : ""}
      <p><strong>Contact person:</strong> ${escapeHtml(org.contact || "Not listed")}</p>
      <p><strong>Phone:</strong> ${escapeHtml(org.phone || "Not listed")}</p>
      ${services ? `<div class="map-card-tags">${services}</div>` : ""}
      ${description ? `<p>${description}</p>` : ""}
      ${org.website ? `<a class="map-card-link" href="${escapeHtml(org.website)}" target="_blank" rel="noopener">Open website</a>` : ""}
    </article>
  `;
}

function renderMap(results) {
  if (directoryMap) {
    directoryMap.remove();
    directoryMap = null;
    directoryMarkers = null;
  }

  if (!window.L) {
    fallbackMap(results);
    return;
  }

  const mapLimit = state.printMode === "map-list" ? results.length : 250;
  const mappedResults = results
    .map((org, index) => ({ org, location: mapLocationForOrg(org, index) }))
    .filter((item) => item.location)
    .slice(0, mapLimit);
  const showNumberedMarkers = state.printMode === "map-list";

  els.mapPanel.innerHTML = `
    <div class="map-canvas" id="directoryMapCanvas" aria-label="Organization map"></div>
    <div class="map-legend" aria-label="Map legend">
      <div class="map-legend-item"><span class="map-legend-dot" style="background:#087f83"></span>Actual address</div>
      <div class="map-legend-item"><span class="map-legend-dot" style="background:#276fbf"></span>County center estimate</div>
      ${selectedMapCounties(results).length ? `<div class="map-legend-item"><span class="map-legend-dot" style="background:#7c2dff"></span>Filtered county border</div>` : ""}
    </div>
    <div class="map-notice">
      Showing ${mappedResults.length} mapped ${mappedResults.length === 1 ? "organization" : "organizations"}${results.length > mappedResults.length ? ` of ${results.length} matching results` : ""}.
      Pins use organization addresses when available, otherwise the served county center.
    </div>
  `;

  directoryMap = L.map("directoryMapCanvas", { scrollWheelZoom: true });
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 18,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
  }).addTo(directoryMap);
  directoryMarkers = L.layerGroup().addTo(directoryMap);

  const bounds = [];
  mappedResults.forEach(({ org, location }, index) => {
    const marker = showNumberedMarkers
      ? L.marker([location.lat, location.lng], {
          icon: L.divIcon({
            className: `map-number-marker ${org.geo ? "actual" : "estimate"}`,
            html: `<svg viewBox="0 0 26 26" aria-hidden="true"><circle cx="13" cy="13" r="11" fill="${org.geo ? "#087f83" : "#276fbf"}" stroke="#ffffff" stroke-width="2"></circle><text x="13" y="17" text-anchor="middle" font-size="10" fill="#ffffff" font-weight="800">${index + 1}</text></svg>`,
            iconSize: [26, 26],
            iconAnchor: [13, 13]
          })
        })
      : L.circleMarker([location.lat, location.lng], {
          radius: 8,
          color: "#ffffff",
          weight: 2,
          fillColor: org.geo ? "#087f83" : "#276fbf",
          fillOpacity: .95
        });
    marker.bindTooltip(escapeHtml(org.name), {
      direction: "top",
      offset: [0, -10],
      opacity: 1,
      sticky: true
    });
    marker.bindPopup(mapCardHtml(org, location), {
      maxWidth: 340,
      minWidth: 260,
      className: "directory-map-popup"
    });
    marker.on("mouseover", () => marker.openTooltip());
    marker.on("mouseout", () => marker.closeTooltip());
    marker.addTo(directoryMarkers);
    bounds.push([location.lat, location.lng]);
  });

  if (bounds.length) {
    directoryMap.fitBounds(bounds, { padding: [36, 36], maxZoom: 9 });
  } else {
    directoryMap.setView([35.86, -86.66], 7);
  }

  window.setTimeout(() => directoryMap?.invalidateSize(), 0);
  renderCountyBorders(selectedMapCounties(results)).then(bringOrgMarkersToFront);
}

function renderPrintView(results) {
  const filterSummary = [];
  Object.entries(state.filters).forEach(([key, values]) => {
    values.forEach((value) => filterSummary.push(value));
  });
  const printableMapRows = printableMapData(results);
  const printCounties = selectedMapCounties(results);
  const mapOnly = state.printMode === "map";
  const mapWithList = state.printMode === "map-list";
  if (mapOnly || mapWithList) {
    const printMapMarkup = `
      <section class="print-map">
        <h2>Participating Organization Directory Map</h2>
        <p><strong>Search:</strong> ${escapeHtml(state.search || "None")}</p>
        <p><strong>Filters:</strong> ${escapeHtml(filterSummary.join(", ") || "None")}</p>
        <p><strong>Mapped locations:</strong> ${printableMapRows.length}</p>
        <div class="print-map-legend">
          <span>Teal: actual address</span>
          <span>Blue: county center estimate</span>
          ${printCounties.length ? "<span>Purple: selected county area</span>" : ""}
        </div>
        ${printableMapRows.length ? `<div class="print-map-frame"><div class="print-map-fallback">Map preview unavailable in this print view.</div>${printMapSvg(printableMapRows, printCounties)}</div>` : "<p>No mapped locations are available for the current results.</p>"}
      </section>
    `;
    els.printView.innerHTML = `
      <h1>${mapOnly ? "Participating Organization Directory Map" : "Participating Organization Directory Map List"}</h1>
      ${printMapMarkup}
      ${mapOnly ? "" : `
      <p><strong>Search:</strong> ${escapeHtml(state.search || "None")}</p>
      <p><strong>Filters:</strong> ${escapeHtml(filterSummary.join(", ") || "None")}</p>
      <p><strong>Results:</strong> ${results.length}</p>
      ${results.map((org, index) => `
        <section class="print-org">
          <h2>${index + 1}. ${escapeHtml(org.name)}</h2>
          <p><strong>Serves:</strong> ${escapeHtml(summarizeList(org.counties, 999, "County not listed"))}</p>
          <p><strong>Type:</strong> ${escapeHtml(org.organizationType)}</p>
          <p><strong>Address:</strong> ${escapeHtml(org.address || "Not listed")}</p>
          <p><strong>Contact person:</strong> ${escapeHtml(org.contact || "Not listed")}</p>
          <p><strong>Phone:</strong> ${escapeHtml(org.phone || "Not listed")}</p>
          <p><strong>Email:</strong> ${escapeHtml(org.email || "Not listed")}</p>
          <p><strong>Services:</strong> ${escapeHtml([...org.mainServices, ...org.additionalServices].join(", "))}</p>
          <p>${escapeHtml(org.description)}</p>
        </section>
      `).join("")}
      `}
    `;
    return;
  }
  els.printView.innerHTML = `
    <h1>${mapOnly ? "Participating Organization Directory Map" : "Participating Organization Directory"}</h1>
    <p><strong>Search:</strong> ${escapeHtml(state.search || "None")}</p>
    <p><strong>Filters:</strong> ${escapeHtml(filterSummary.join(", ") || "None")}</p>
    <p><strong>Results:</strong> ${results.length}</p>
    ${results.map((org) => `
      <section class="print-org">
        <h2>${escapeHtml(org.name)}</h2>
        <p><strong>Serves:</strong> ${escapeHtml(summarizeList(org.counties, 999, "County not listed"))}</p>
        <p><strong>Type:</strong> ${escapeHtml(org.organizationType)}</p>
        <p><strong>Address:</strong> ${escapeHtml(org.address || "Not listed")}</p>
        <p><strong>Contact person:</strong> ${escapeHtml(org.contact || "Not listed")}</p>
        <p><strong>Phone:</strong> ${escapeHtml(org.phone || "Not listed")}</p>
        <p><strong>Email:</strong> ${escapeHtml(org.email || "Not listed")}</p>
        <p><strong>Services:</strong> ${escapeHtml([...org.mainServices, ...org.additionalServices].join(", "))}</p>
        <p>${escapeHtml(org.description)}</p>
      </section>
    `).join("")}
  `;
}

function renderResults() {
  const results = getFilteredOrganizations();
  els.loadingState.classList.toggle("hidden", !state.isLoading && !state.isQuerying);
  els.errorState.classList.toggle("hidden", !qlikLoadError);
  els.errorState.textContent = qlikLoadError ? `Unable to load Qlik data: ${qlikLoadError}` : "";

  if (state.isLoading) {
    els.resultCount.textContent = "Loading organizations";
    els.resultSubtext.textContent = "Asking Qlik for the first page of directory results.";
    els.resultsGrid.classList.add("hidden");
    els.mapPanel.classList.remove("active");
    els.emptyState.classList.remove("active");
    return;
  }

  const displayTotal = state.hasMoreResults ? state.totalResults : results.length;
  const totalText = displayTotal === 1 ? "1 matching organization" : `${displayTotal} matching organizations`;
  els.resultCount.textContent = totalText;

  const activeParts = [];
  Object.entries(state.filters).forEach(([key, values]) => {
    if (values.size > 0) activeParts.push(...values);
  });
  els.resultSubtext.textContent = activeParts.length
    ? `Showing results for ${activeParts.slice(0, 3).join(", ")}${activeParts.length > 3 ? " and more" : ""}.`
    : "Use search or filters to narrow the directory.";

  const hasResults = results.length > 0;
  const showMap = state.view === "map" && hasResults;
  const visibleResults = results;
  els.resultsGrid.classList.toggle("hidden", !hasResults || showMap || Boolean(qlikLoadError));
  els.mapPanel.classList.toggle("active", showMap);
  els.emptyState.classList.toggle("active", !hasResults && !qlikLoadError);
  els.paginationActions.classList.toggle("hidden", !hasResults || showMap || !state.hasMoreResults);
  els.loadMoreResults.disabled = state.isQuerying;
  els.loadMoreResults.textContent = state.isQuerying
    ? "Loading more organizations..."
    : `Load more organizations (${Math.max(0, Math.min(PAGE_SIZE, state.totalResults - organizations.length))} more)`;

  renderCards(visibleResults);
  if (showMap) renderMap(results);
  renderPrintView(results);
  queueAddressDistanceLookups(visibleResults);
}

function renderViewButtons() {
  document.querySelectorAll("[data-view]").forEach((button) => {
    button.classList.toggle("active", button.dataset.view === state.view);
  });
}

function renderMapPrintButtons() {
  const show = state.view === "map";
  els.printMapOnly.classList.toggle("hidden", !show);
  els.printMapList.classList.toggle("hidden", !show);
}

function render(options = {}) {
  const shouldRenderFilters = options.filters !== false;
  const shouldRenderResults = options.results !== false;
  renderQuickNeeds();
  if (shouldRenderFilters) renderFilters();
  renderActiveFilters();
  renderViewButtons();
  renderMapPrintButtons();
  if (shouldRenderResults) renderResults();
}

const requestDirectoryPageDebounced = debounce(() => requestDirectoryPage(), 300);

els.searchInput.addEventListener("input", (event) => {
  state.search = event.target.value;
  state.activeQuickNeed = "";
  resetVisibleResults();
  requestDirectoryPageDebounced();
});

els.searchButton.addEventListener("click", () => {
  state.search = els.searchInput.value;
  resetVisibleResults();
  requestDirectoryPage();
});

els.toggleFilters.addEventListener("click", () => {
  state.filtersOpen = !state.filtersOpen;
  render();
});

els.clearFiltersTop.addEventListener("click", clearFilters);
els.clearFiltersEmpty.addEventListener("click", clearFilters);
els.loadMoreResults.addEventListener("click", () => {
  requestDirectoryPage({ append: true });
});
let viewBeforePrint = "cards";

function standalonePrintDocument(title, contentHtml) {
  return `<!doctype html>
    <html>
      <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>${escapeHtml(title)}</title>
        <style>
          @page { margin: .45in; }
          * { box-sizing: border-box; }
          body {
            margin: 0;
            color: #111;
            background: #fff;
            font: 11pt/1.4 Arial, sans-serif;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
          }
          h1 { margin: 0 0 12pt; font-size: 18pt; }
          p { margin: 3pt 0; }
          .print-org {
            break-inside: avoid;
            margin: 0 0 14pt;
            padding-bottom: 10pt;
            border-bottom: 1px solid #ccc;
          }
          .print-org h2 {
            margin: 0 0 4pt;
            font-size: 14pt;
          }
          .print-map {
            break-inside: avoid;
            margin: 0 0 16pt;
            padding: 10pt;
            border: 1px solid #aaa;
          }
          .print-map h2 {
            margin: 0 0 6pt;
            font-size: 14pt;
          }
          .print-map-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 8pt 14pt;
            margin: 6pt 0 10pt;
            font-size: 10pt;
          }
          .print-map-frame {
            position: relative;
            width: 100%;
            min-height: 4.6in;
            margin: 8pt 0;
            border: 1px solid #bbb;
            background: #eef5f6;
          }
          .print-map-fallback {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            color: #53636a;
            font-size: 10pt;
            text-align: center;
          }
          .print-map-svg {
            position: relative;
            z-index: 1;
            display: block;
            width: 100%;
            max-width: 100%;
            height: auto;
            margin: 8pt 0;
          }
          @media screen {
            body { padding: 18px; }
          }
        </style>
      </head>
      <body>${contentHtml}</body>
    </html>`;
}

function printStandaloneContent(title, contentHtml, delay = 300) {
  const printHtml = standalonePrintDocument(title, contentHtml);
  const printPopup = window.open("", "_blank");
  if (printPopup) {
    printPopup.document.open();
    printPopup.document.write(printHtml);
    printPopup.document.close();
    printPopup.focus();
    window.setTimeout(() => printPopup.print(), delay);
    return true;
  }

  const printFrame = document.createElement("iframe");
  printFrame.title = title;
  printFrame.style.position = "fixed";
  printFrame.style.right = "0";
  printFrame.style.bottom = "0";
  printFrame.style.width = "0";
  printFrame.style.height = "0";
  printFrame.style.border = "0";
  document.body.appendChild(printFrame);

  const printWindow = printFrame.contentWindow;
  const printDocument = printWindow?.document;
  if (!printWindow || !printDocument) {
    printFrame.remove();
    return false;
  }

  printDocument.open();
  printDocument.write(printHtml);
  printDocument.close();
  window.setTimeout(() => {
    printWindow.focus();
    printWindow.print();
    window.setTimeout(() => printFrame.remove(), 1000);
  }, delay);
  return true;
}

function printDirectory(mode) {
  viewBeforePrint = state.view;
  state.printMode = mode;
  document.body.classList.toggle("print-map-only", mode === "map");
  document.body.classList.toggle("print-map-list", mode === "map-list");
  if (mode === "map" || mode === "map-list") state.view = "map";
  render({ filters: false });
  const title = mode === "map"
    ? "Participating Organization Directory Map"
    : mode === "map-list"
      ? "Participating Organization Directory Map List"
      : "Participating Organization Directory Results";
  printStandaloneContent(title, els.printView.innerHTML, mode === "results" ? 300 : 1800);
  document.body.classList.remove("print-map-only", "print-map-list");
  state.view = viewBeforePrint;
  state.printMode = "results";
  render({ filters: false });
}

window.addEventListener("afterprint", () => {
  document.body.classList.remove("print-map-only", "print-map-list");
  state.view = viewBeforePrint;
  state.printMode = "results";
  render({ filters: false });
});

els.printResults.addEventListener("click", () => {
  printDirectory("results");
});
els.printMapOnly.addEventListener("click", () => {
  printDirectory("map");
});
els.printMapList.addEventListener("click", () => {
  printDirectory("map-list");
});
els.closeNearby.addEventListener("click", closeNearbyModal);
els.nearbyModal.addEventListener("click", (event) => {
  if (event.target === els.nearbyModal) closeNearbyModal();
});
els.useLocation.addEventListener("click", () => {
  if (!navigator.geolocation) {
    els.locationStatus.textContent = "This browser does not support location sharing. Try ZIP/county or choose a county.";
    return;
  }
  els.locationStatus.textContent = "Asking your browser for location permission...";
  navigator.geolocation.getCurrentPosition((position) => {
    state.userLocation = {
      latitude: position.coords.latitude,
      longitude: position.coords.longitude
    };
    const county = nearestCounty(position.coords.latitude, position.coords.longitude);
    if (county) {
      els.locationStatus.textContent = `Nearest available county appears to be ${county}. Applying that filter and showing approximate distances.`;
      applyCountyFilter(county);
    } else {
      els.locationStatus.textContent = "Location received, but no matching county was available in the directory data.";
    }
  }, () => {
    els.locationStatus.textContent = "Location was not shared. Try entering ZIP/county or choosing a county.";
  }, {
    enableHighAccuracy: false,
    timeout: 10000,
    maximumAge: 300000
  });
});
els.applyNearbyText.addEventListener("click", () => {
  const county = normalizeCountyInput(els.nearbyText.value);
  if (county) {
    applyCountyFilter(county);
  } else {
    els.nearbyText.value = "";
    els.nearbyText.placeholder = "No match found. Try a county name.";
  }
});
els.nearbyText.addEventListener("keydown", (event) => {
  if (event.key === "Enter") els.applyNearbyText.click();
});
els.applyNearbyCounty.addEventListener("click", () => {
  applyCountyFilter(els.nearbyCountySelect.value);
});

document.querySelectorAll("[data-view]").forEach((button) => {
  button.addEventListener("click", () => {
    state.view = button.dataset.view;
    render({ filters: false });
  });
});

loadEmergencyHelpContent();
render();
loadQlikOrganizations();
