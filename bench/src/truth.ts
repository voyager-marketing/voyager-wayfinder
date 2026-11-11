// References into fixtures/truth.json. Questions name a key path, never a copied value,
// so the expected answer always comes from the seed and not from the scanner under test.
//
//   site.title                                      one value
//   post_types[id=pt:service].fields[*].key         [*] fans out, giving a list
//   keys[used_in=part:footer].key                   a selector on an array field matches by membership;
//                                                   a selector matching more or less than one item gives a list

export type Json = null | boolean | number | string | Json[] | { [key: string]: Json };

const TOKEN = /([^.[\]]+)|\[([^\]]*)\]/g;

function isObject(node: Json): node is { [key: string]: Json } {
  return node !== null && typeof node === 'object' && !Array.isArray(node);
}

function matches(value: Json | undefined, wanted: string): boolean {
  return Array.isArray(value) ? value.some((v) => String(v) === wanted) : String(value) === wanted;
}

/** Resolves a reference to one value, or to a list once it fans out. Undefined when an unfanned path names nothing. */
export function resolveRef(truth: Json, ref: string): Json | undefined {
  let nodes: Json[] = [truth];
  let fanned = false;
  for (const [, key, selector] of ref.matchAll(TOKEN)) {
    const next: Json[] = [];
    for (const node of nodes) {
      if (key !== undefined) {
        if (isObject(node) && key in node) next.push(node[key]);
      } else if (Array.isArray(node)) {
        if (selector === '*') {
          next.push(...node);
          fanned = true;
          continue;
        }
        const eq = selector.indexOf('=');
        const [field, wanted] = [selector.slice(0, eq), selector.slice(eq + 1)];
        const hits = node.filter((item) => isObject(item) && matches(item[field], wanted));
        if (hits.length !== 1) fanned = true;
        next.push(...hits);
      }
    }
    nodes = next;
  }
  if (fanned) return nodes;
  return nodes.length === 1 ? nodes[0] : undefined;
}

/** Every scalar a set of references resolves to, flattened. Throws on a reference that names nothing. */
export function expectedValues(truth: Json, refs: string[]): string[] {
  const out: string[] = [];
  const walk = (node: Json): void => {
    if (Array.isArray(node)) node.forEach(walk);
    else if (isObject(node)) throw new Error('reference resolves to an object; name a scalar key');
    else out.push(String(node));
  };
  for (const ref of refs) {
    const value = resolveRef(truth, ref);
    if (value === undefined || (Array.isArray(value) && value.length === 0)) {
      throw new Error(`reference resolves to nothing in truth.json: ${ref}`);
    }
    walk(value);
  }
  return out;
}
