export type CsvTable = {
    headers: string[];
    rows: Record<string, string>[];
};

const DEFAULT_HEADERS = ['email', 'name', 'job_title', 'company', 'tags'];

export function parseCsv(text: string): string[][] {
    const input = text
        .replace(/^\uFEFF/, '')
        .replace(/\r\n/g, '\n')
        .replace(/\r/g, '\n');
    const rows: string[][] = [];
    let row: string[] = [];
    let field = '';
    let inQuotes = false;

    for (let index = 0; index < input.length; index++) {
        const char = input[index];

        if (inQuotes) {
            if (char === '"') {
                if (input[index + 1] === '"') {
                    field += '"';
                    index += 1;
                } else {
                    inQuotes = false;
                }
            } else {
                field += char;
            }
        } else if (char === '"') {
            inQuotes = true;
        } else if (char === ',') {
            row.push(field.trim());
            field = '';
        } else if (char === '\n') {
            row.push(field.trim());
            field = '';

            if (row.some((cell) => cell !== '')) {
                rows.push(row);
            }

            row = [];
        } else {
            field += char;
        }
    }

    row.push(field.trim());

    if (row.some((cell) => cell !== '')) {
        rows.push(row);
    }

    return rows;
}

export function csvTable(
    text: string,
    defaultHeaders: string[] = DEFAULT_HEADERS,
): CsvTable {
    const grid = parseCsv(text);

    if (!grid.length) {
        return { headers: [], rows: [] };
    }

    const first = grid[0];
    const headerless = first.some((cell) => cell.includes('@'));

    if (headerless) {
        return {
            headers: defaultHeaders,
            rows: grid.map((cells) =>
                Object.fromEntries(
                    defaultHeaders.map((header, index) => [
                        header,
                        cells[index] ?? '',
                    ]),
                ),
            ),
        };
    }

    const rawHeaders = first.map(
        (header, index) => header || `column_${index + 1}`,
    );
    const issuedHeaders = new Set<string>();
    const headers = rawHeaders.map((header) => {
        let candidate = header;
        let occurrence = 1;

        while (issuedHeaders.has(candidate.toLocaleLowerCase())) {
            occurrence++;
            candidate = `${header} (${occurrence})`;
        }

        issuedHeaders.add(candidate.toLocaleLowerCase());

        return candidate;
    });

    return {
        headers,
        rows: grid
            .slice(1)
            .map((cells) =>
                Object.fromEntries(
                    headers.map((header, index) => [
                        header,
                        cells[index] ?? '',
                    ]),
                ),
            ),
    };
}

const HEADER_ALIASES: Record<string, string[]> = {
    email: ['email', 'e-mail', 'email address', 'email_address'],
    name: ['name', 'full name', 'full_name', 'fullname'],
    first_name: [
        'first name',
        'first_name',
        'firstname',
        'given name',
        'given_name',
    ],
    job_title: ['job title', 'job_title', 'title', 'role'],
    company: ['company', 'account', 'organisation', 'organization'],
    tags: ['tags', 'tag'],
};

export function guessCampaignImportMapping(
    headers: string[],
): Record<'email' | 'name' | 'first_name' | 'company', string> {
    const normalised = headers.map((header) => header.toLocaleLowerCase());

    function match(field: 'email' | 'name' | 'first_name' | 'company'): string {
        const aliases = HEADER_ALIASES[field];
        const index = normalised.findIndex((header) =>
            aliases.includes(header),
        );

        return index >= 0 ? headers[index] : '';
    }

    return {
        email: match('email'),
        name: match('name'),
        first_name: match('first_name'),
        company: match('company'),
    };
}

export function guessPersonImportMapping(
    headers: string[],
): Record<'email' | 'name' | 'job_title' | 'company' | 'tags', string> {
    const normalised = headers.map((header) => header.toLocaleLowerCase());

    function match(field: keyof typeof HEADER_ALIASES): string {
        const aliases = HEADER_ALIASES[field];
        const index = normalised.findIndex((header) =>
            aliases.includes(header),
        );

        return index >= 0 ? headers[index] : '';
    }

    return {
        email: match('email'),
        name: match('name'),
        job_title: match('job_title'),
        company: match('company'),
        tags: match('tags'),
    };
}
