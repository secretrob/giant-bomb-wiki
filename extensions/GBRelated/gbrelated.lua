if not mw.ext then
    mw.ext = {}
end

local gbrelated = {}
local php = mw_interface

function gbrelated.get()
    return php.get()
end

mw.ext.gbrelated = gbrelated

return gbrelated
