String.prototype.upFirst = function(){ var s = this; return s.charAt(0).toUpperCase() + s.slice(1); };
function getObj(id, arr, key) { key = key || 'id'; var o = null; $.each(arr, function (i, el) { if (el[key] == id) { o=el; return; } }); return o; };

function pagination(currentPage, nrOfPages) {
    var delta = 4,
        range = [],
        rangeWithDots = [],
        l;

    range.push(1);

    if (nrOfPages <= 1){
 	return range;
    }

    for (let i = currentPage - delta; i <= currentPage + delta; i++) {
        if (i < nrOfPages && i > 1) {
            range.push(i);
        }
    }
    range.push(nrOfPages);

    for (let i of range) {
        if (l) {
            if (i - l === 2) {
                rangeWithDots.push(l + 1);
            } else if (i - l !== 1) {
                rangeWithDots.push('...');
            }
        }
        rangeWithDots.push(i);
        l = i;
    }

    return rangeWithDots;
}

function pages(total, offset, limit) {
    if (total == 0) return pagination(1,1);
    return pagination(Math.round(offset / limit) + 1,
                        Math.ceil(total / limit));
}

function sort_dir(st, col){
    var order = (st+'').split(',');
    if (order[0] == col)
        return order[1];
    return '';
}

function setError(st) {

    $(st).removeClass('text-success text-info').addClass('text-danger').children('i').removeClass('fa-ellipsis-h fa-check').addClass('fa-times');
    $(st).delay(10000).queue(function(){
        $(this).removeClass('text-success text-info text-danger').children('i').removeClass('fa-ellipsis-h fa-check fa-times');
    });
}

function setSaved(st) {

    $(st).removeClass('text-success text-info').addClass('text-success').children('i').removeClass('fa-ellipsis-h fa-times').addClass('fa-check');
    $(st).delay(10000).queue(function(){
        $(this).removeClass('text-success text-info text-danger').children('i').removeClass('fa-ellipsis-h fa-check fa-times');
    });
}

function setSaving(st) {
    $(st).removeClass('text-success text-danger').addClass('text-info').children('i').removeClass('fa-times fa-check').addClass('fa-ellipsis-h');
}