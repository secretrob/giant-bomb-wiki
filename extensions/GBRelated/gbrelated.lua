if not mw.ext then
    mw.ext = {}
end

local gbrelated = {}
local php = mw_interface

function gbrelated.get()
    return php.get()
end

function gbrelated.popular(limit, offset, platform)
    return php.popular(limit, offset, platform)
end

mw.ext.gbrelated = gbrelated

return gbrelated
