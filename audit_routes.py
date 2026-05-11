import json
with open('routes.json', 'r', encoding='utf-8-sig') as f:
    routes = json.load(f)
authed_no_module = []
for r in routes:
    mw = r.get('middleware', [])
    uri = r.get('uri','')
    methods = ','.join(r.get('methods',[]))
    mw_str = str(mw)
    if 'Authenticate:sanctum' in mw_str and 'EnsureTenant' in mw_str and 'EnsureModuleEnabled' not in mw_str and 'EnsureRole' not in mw_str:
        authed_no_module.append(f'{methods} {uri}')
for p in authed_no_module:
    print(p)
print(f'Total: {len(authed_no_module)}')
